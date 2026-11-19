<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Chain;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Chain\ChainRegistry;
use Hardcastle\LedgerDirect\Chain\InterledgerGateway;
use Hardcastle\LedgerDirect\Chain\InterledgerPackageConfig;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsException;
use Hardcastle\LedgerDirect\Interledger\IncomingPaymentService;
use Hardcastle\LedgerDirect\Interledger\ReceiptSyncService;
use Hardcastle\LedgerDirect\Interledger\Testing\FakeOpenPayments;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The gateway decides whether Interledger can be used at all, and builds
 * the package's services when it can. Nothing of the package is touched
 * while it cannot — that is what makes the package optional.
 */
class InterledgerGatewayTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private RecordingLogger $logger;

    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->cache = new InMemoryCache();
    }

    public function testWithoutTheCardFilledInNothingIsAvailable(): void
    {
        $gateway = $this->gateway(Fixtures::getStaticConfiguration());

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('', $gateway->walletAddress());
        $this->assertNull($gateway->walletAssetCode(), 'and the checkout filter therefore offers nothing');
    }

    public function testACompleteCardMakesItAvailable(): void
    {
        $gateway = $this->gateway(Fixtures::getStaticInterledgerConfiguration());

        $this->assertTrue($gateway->isAvailable());
        $this->assertSame(Fixtures::INTERLEDGER_WALLET, $gateway->walletAddress());
    }

    /**
     * The switch decides whether new payments are taken. The payments already
     * requested must stay readable, so the services are still built — and the
     * package still refuses a new quote, because the asset is not enabled.
     */
    public function testASwitchedOffChainTakesNothingNewButStillReadsItsPayments(): void
    {
        $switchedOff = ['LedgerDirect.config.interledgerEnabled' => false] + Fixtures::getStaticInterledgerConfiguration();
        $gateway = $this->gateway($switchedOff, new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2));

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('', $gateway->walletAddress());
        $this->assertNull($gateway->walletAssetCode(), 'the checkout filter offers nothing');
        $this->assertInstanceOf(ReceiptSyncService::class, $gateway->receiptSync());

        $this->expectException(AssetNotAcceptedException::class);
        $gateway->incomingPaymentService()->quoteForOrder(19.99, 'EUR', 'EUR');
    }

    /**
     * A mainnet toggle stored before the preview rule: nothing new is taken, and
     * what the package is told is the mainnet and its wallet address, never the
     * testnet one - an order paid there is read where it was paid.
     */
    public function testOnTheMainnetAPreviewTakesNothingNewAndThePackageIsToldTheMainnetWallet(): void
    {
        $mainnetWallet = 'https://ilp.example.com/acme';
        $configuration = ['LedgerDirect.config.useInterledgerTestnet' => false] + Fixtures::getStaticInterledgerConfiguration()
            + ['LedgerDirect.config.interledgerMainnetDestinationAccount' => $mainnetWallet];
        $gateway = $this->gateway($configuration, new FakeOpenPayments($mainnetWallet, 'EUR', 2));

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('', $gateway->walletAddress());
        $this->assertNull($gateway->walletAssetCode(), 'the checkout filter offers nothing');

        $configurationService = ConfigurationServiceMock::createInstance($configuration);
        $forPackage = new InterledgerPackageConfig($configurationService, new ShopwareConfigProvider($configurationService));
        $this->assertSame('mainnet', $forPackage->getNetwork(ChainRegistry::INTERLEDGER));
        $this->assertSame($mainnetWallet, $forPackage->getDestinationAccount(ChainRegistry::INTERLEDGER));
        $this->assertFalse($forPackage->isAssetEnabled(ChainRegistry::INTERLEDGER, 'EUR'), 'so the package refuses to quote');

        $this->assertInstanceOf(ReceiptSyncService::class, $gateway->receiptSync());
        $this->expectException(AssetNotAcceptedException::class);
        $gateway->incomingPaymentService()->quoteForOrder(19.99, 'EUR', 'EUR');
    }

    /**
     * A Dassie node as the wallet: it registers nobody's key but its own, so
     * there is no key id to configure. The shop is then a client known by its
     * key alone, and the key id the package signs with is the key's thumbprint.
     */
    public function testWithoutAKeyIdTheShopIsAClientKnownByItsKeyAlone(): void
    {
        $configuration = ['LedgerDirect.config.interledgerKeyId' => '', 'LedgerDirect.config.interledgerPrivateKey' => self::rfc8037Key()]
            + Fixtures::getStaticInterledgerConfiguration();
        $gateway = $this->gateway($configuration, new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'XRP', 9));

        $this->assertTrue($gateway->isAvailable());
        $this->assertSame('XRP', $gateway->walletAssetCode());

        $credentials = (new \ReflectionProperty($gateway->root(), 'credentials'))->getValue($gateway->root());
        $this->assertTrue($credentials->isKeyOnly());
        $this->assertSame('kPrK_qmxVWaYVA9wwBF6Iuo3vVzz7TxHCTwXBygrS4k', $credentials->keyId, 'the thumbprint RFC 8037 publishes for this key');
    }

    /**
     * The thumbprint is computed from the key, so a value that is not an
     * Ed25519 key is refused where a registered key id would have let it
     * through to fail at the first signature. Logged, and nothing is offered.
     */
    public function testWithoutAKeyIdAValueThatIsNoKeyIsNotUsedAndIsLogged(): void
    {
        $gateway = $this->gateway(['LedgerDirect.config.interledgerKeyId' => ''] + Fixtures::getStaticInterledgerConfiguration());

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('warning', $this->logger->records()[0]['level']);
        $this->assertStringContainsString('Ed25519', $this->logger->records()[0]['context']['exception']);
    }

    public function testAWalletAddressWrittenAsAPaymentPointerIsUsedAsItsUrl(): void
    {
        $configuration = Fixtures::getStaticInterledgerConfiguration();
        $configuration['LedgerDirect.config.interledgerTestnetDestinationAccount'] = '$ilp.example.test/merchant';

        $this->assertSame('https://ilp.example.test/merchant', $this->gateway($configuration)->walletAddress());
    }

    public function testAKeyInTheWrongShapeIsNotUsedAndIsLogged(): void
    {
        $configuration = Fixtures::getStaticInterledgerConfiguration();
        // The PEM itself, not its base64: what the SDK's signer cannot work with.
        $configuration['LedgerDirect.config.interledgerPrivateKey'] = "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----";

        $this->assertFalse($this->gateway($configuration)->isAvailable());
        $this->assertSame('warning', $this->logger->records()[0]['level']);
    }

    public function testTheWalletsAssetIsReadOnceAndThenCached(): void
    {
        $wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $gateway = $this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet);

        $this->assertSame('EUR', $gateway->walletAssetCode());
        $this->assertSame('EUR', $this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet)->walletAssetCode());
        $this->assertSame(1, $wallet->addressReads, 'the checkout asks on every render; the wallet does not');
    }

    public function testAWalletThatCannotBeReachedHasNoAssetAndIsLogged(): void
    {
        $wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $wallet->failWith = new OpenPaymentsException('503 Service Unavailable');

        $this->assertNull($this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet)->walletAssetCode());
        $this->assertSame('warning', $this->logger->records()[0]['level']);
    }

    /**
     * The checkout's payment-method list asks for every Interledger method, so
     * a wallet that is down must be asked once and not once per method.
     */
    public function testAnUnreachableWalletIsRememberedForAMoment(): void
    {
        $wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $wallet->failWith = new OpenPaymentsException('503 Service Unavailable');

        self::assertNull($this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet)->walletAssetCode());
        self::assertNull($this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet)->walletAssetCode());

        // Two gateways stand for two requests; they share the cache, and only a call that
        // actually asked the wallet logs. (The fake's own counter sees successful reads only.)
        $attempts = array_filter(
            $this->logger->records(),
            static fn (array $record): bool => str_contains($record['message'], 'could not read the Interledger wallet'),
        );
        self::assertCount(1, $attempts, 'the second call reads the cache, not the wallet');
    }

    public function testTheServicesComeOutOfThePackageWhenItIsConfigured(): void
    {
        $gateway = $this->gateway(Fixtures::getStaticInterledgerConfiguration(), new FakeOpenPayments());

        $this->assertInstanceOf(IncomingPaymentService::class, $gateway->incomingPaymentService());
        $this->assertInstanceOf(ReceiptSyncService::class, $gateway->receiptSync());
        $this->assertSame($gateway->receiptSync(), $gateway->receiptSync(), 'built once per request');
    }

    public function testAskingForTheServicesWithoutConfigurationSaysSo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        $this->gateway(Fixtures::getStaticConfiguration())->incomingPaymentService();
    }

    /** The Ed25519 key of RFC 8037, appendix A, as a shop holds a key: base64 of its PKCS#8 PEM. */
    private static function rfc8037Key(): string
    {
        $seed = base64_decode(strtr('nWGxne_9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A', '-_', '+/'), true);
        $der = hex2bin('302e020100300506032b657004220420') . $seed;

        return base64_encode("-----BEGIN PRIVATE KEY-----\n" . base64_encode($der) . "\n-----END PRIVATE KEY-----\n");
    }

    private function gateway(array $configuration, ?FakeOpenPayments $wallet = null): InterledgerGateway
    {
        $configurationService = ConfigurationServiceMock::createInstance($configuration);

        $gateway = new InterledgerGateway(
            $configurationService,
            new ShopwareConfigProvider($configurationService),
            new FakeHttpClient(),
            new HttpFactory(),
            Mockery::mock(Connection::class),
            $this->cache,
            $this->logger,
        );

        if ($wallet !== null) {
            $gateway->useWallet($wallet);
        }

        return $gateway;
    }
}
