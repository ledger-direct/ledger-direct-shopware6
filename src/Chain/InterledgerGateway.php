<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Chain;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Interledger\Chain as InterledgerChain;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsInterface;
use Hardcastle\LedgerDirect\Interledger\Credentials;
use Hardcastle\LedgerDirect\Interledger\IncomingPaymentService;
use Hardcastle\LedgerDirect\Interledger\LedgerDirectInterledger;
use Hardcastle\LedgerDirect\Interledger\ReceiptSyncService;
use Hardcastle\LedgerDirect\Port\DbalInterledgerRepository;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;

/**
 * Wires up the Interledger package when it is there and the merchant has
 * configured it — and answers `false` to everything when it is not.
 *
 * It is a gateway and not a set of DI entries for one reason: the package is
 * optional (see ChainRegistry), so nothing that names its classes may be
 * constructed unless it is installed. Everything this constructor takes is
 * the plugin's, the core's or a PSR interface; the package's classes appear
 * only inside methods that are not reached while `isAvailable()` is false.
 *
 * The access token is deliberately **not** cached: it would mean a bearer
 * token for the merchant's wallet sitting in the shop's object cache. The
 * price is one grant request per read of an incoming payment, and
 * OrderTransactionService holds those to one per order within the core's
 * sync interval.
 *
 * Taking new payments and reading the ones already requested are two
 * questions. `isAvailable()` and `walletAddress()` answer the first and
 * follow the chain's switch; `root()` serves the second too and needs only
 * the configured wallet address and key (see InterledgerPackageConfig).
 */
final class InterledgerGateway
{
    /** The wallet's asset code, cached: the checkout filter asks on every render. */
    private const ASSET_CACHE_KEY = 'ledger-direct.interledger.asset.v1';

    private const ASSET_CACHE_TTL = 300;

    /**
     * What is remembered when the wallet could not be read, and for how long.
     * Without it, every payment-method list of every checkout retries a wallet
     * that is down and waits for it to fail, while the answer is already known:
     * offer nothing. Short, so a wallet that comes back is offered again soon.
     */
    private const UNREACHABLE = 'unreachable';

    private const UNREACHABLE_CACHE_TTL = 30;

    public const KEY_ID_CONFIG = 'interledgerKeyId';

    public const PRIVATE_KEY_CONFIG = 'interledgerPrivateKey';

    private ?LedgerDirectInterledger $root = null;

    private ?OpenPaymentsInterface $wallet = null;

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly ShopwareConfigProvider $configProvider,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Test seam: use this wallet instead of talking to a real one. The
     * package ships Testing\FakeOpenPayments for exactly this, so an
     * adapter's tests need no network, no grant and no test money.
     *
     * @internal
     */
    public function useWallet(OpenPaymentsInterface $wallet): void
    {
        $this->wallet = $wallet;
        $this->root = null;
    }

    /**
     * Whether an Interledger payment can be quoted at all: the package is
     * installed, the chain is on with a wallet address, and a key is
     * configured in the shape the SDK signs with.
     */
    public function isAvailable(): bool
    {
        if (!class_exists(LedgerDirectInterledger::class)) {
            return false;
        }

        if ($this->walletAddress() === '') {
            return false;
        }

        return $this->credentials() !== null;
    }

    /**
     * The wallet address new payments go to, normalised; '' when there is
     * none or the chain is switched off.
     */
    public function walletAddress(): string
    {
        $account = $this->configProvider->getDestinationAccount(ChainRegistry::INTERLEDGER);

        return $account === '' ? '' : InterledgerChain::normalizeWalletAddress($account);
    }

    public function incomingPaymentService(): IncomingPaymentService
    {
        return $this->root()->incomingPaymentService();
    }

    public function receiptSync(): ReceiptSyncService
    {
        return $this->root()->receiptSyncService();
    }

    /**
     * The asset the configured wallet address holds — the only asset an
     * Interledger payment can be in. Cached for a few minutes, because the
     * checkout's payment-method list asks on every render; null when the
     * chain is not configured or the wallet cannot be reached, and a null
     * answer means "offer nothing", not "offer everything".
     */
    public function walletAssetCode(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $key = self::ASSET_CACHE_KEY . '.' . hash('sha256', $this->walletAddress());

        try {
            $cached = $this->cache->get($key);

            if ($cached === self::UNREACHABLE) {
                return null;
            }

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: Interledger asset cache read failed', [
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            $assetCode = $this->root()->openPayments()->walletAddress()->assetCode;
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: could not read the Interledger wallet address, offering nothing', [
                'wallet_address' => $this->walletAddress(),
                'exception' => $exception->getMessage(),
            ]);

            try {
                $this->cache->set($key, self::UNREACHABLE, self::UNREACHABLE_CACHE_TTL);
            } catch (Throwable $cacheFailure) {
                $this->logger->warning('LedgerDirect: Interledger asset cache write failed', [
                    'exception' => $cacheFailure->getMessage(),
                ]);
            }

            return null;
        }

        try {
            $this->cache->set($key, $assetCode, self::ASSET_CACHE_TTL);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: Interledger asset cache write failed', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return $assetCode;
    }

    /**
     * The composition root, built once per request.
     *
     * It is built from the configured wallet address whether or not the chain
     * is switched on: an open order's incoming payment has to stay readable
     * after the merchant stops taking new ones.
     *
     * @throws RuntimeException when the package is missing or nothing is configured —
     *     a wiring or configuration mistake, not something a checkout recovers from
     */
    public function root(): LedgerDirectInterledger
    {
        if ($this->root !== null) {
            return $this->root;
        }

        if (!class_exists(LedgerDirectInterledger::class)) {
            throw new RuntimeException(
                'Interledger payments need hardcastle/ledger-direct-interledger: composer require it in the shop.'
            );
        }

        $credentials = $this->credentials();
        $packageConfig = new InterledgerPackageConfig($this->configuration, $this->configProvider);

        if ($packageConfig->getDestinationAccount(ChainRegistry::INTERLEDGER) === '' || $credentials === null) {
            throw new RuntimeException(
                'Interledger is not configured: a wallet address, a key id and a private key are all required.'
            );
        }

        return $this->root = LedgerDirectInterledger::create(
            $this->httpClient,
            $this->requestFactory,
            $this->logger,
            new DbalInterledgerRepository($this->connection),
            $packageConfig,
            $credentials,
            $this->cache,
            // No token cache: see the class comment.
            null,
            openPayments: $this->wallet,
        );
    }

    /**
     * The configured key, or null when it is missing or not in the shape
     * the SDK signs with. A bad key is a configuration mistake the admin
     * has to see, so it is logged once here rather than thrown into a
     * checkout — `isAvailable()` then keeps the methods out of the list.
     */
    private function credentials(): ?Credentials
    {
        $keyId = $this->configuration->getString(self::KEY_ID_CONFIG);
        $privateKey = $this->configuration->getString(self::PRIVATE_KEY_CONFIG);

        if ($keyId === '' || $privateKey === '') {
            return null;
        }

        try {
            return Credentials::fromBase64Pem($keyId, $privateKey);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: the configured Interledger key cannot be used', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
