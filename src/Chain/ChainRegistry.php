<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Chain;

use Hardcastle\LedgerDirect\Core\AssetDescriptor;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\ChainDescriptor;
use Hardcastle\LedgerDirect\Interledger\Chain as InterledgerChain;
use InvalidArgumentException;

/**
 * Every chain this installation can take payments on: the core's catalogue,
 * plus Interledger when the package next to it is installed.
 *
 * It exists because a chain no longer has to come from the core. Interledger
 * speaks Open Payments over an SDK that brings Guzzle, Symfony and PHP 8.3,
 * which is why it lives in `hardcastle/ledger-direct-interledger` and not in
 * a core that stands on PSR interfaces and 8.2 (see that package's README).
 * The plugin therefore asks here rather than asking `ChainCatalog` directly:
 * configuration cards, payment methods, the checkout filter and the payment
 * page all read one list, and it is the same list whether a chain's
 * descriptor came from the core or from a package beside it.
 *
 * Interledger is **optional**: it is a `composer require` away (the plugin
 * suggests it, it is not a hard dependency), and without it this is exactly
 * `ChainCatalog` — no card, no payment methods, nothing to configure. That
 * is also what a store release contains, since the preview chain's package
 * is a dev dependency.
 *
 * Interledger joins the core's catalogue when the package is released
 * (handover §7, item 6: the asset list of a wallet address is dynamic, and
 * what belongs in a static catalogue is still open). Then this class becomes
 * a pass-through and can go.
 */
final class ChainRegistry
{
    public const XRPL = ChainCatalog::XRPL;

    public const STELLAR = ChainCatalog::STELLAR;

    public const XAHAU = ChainCatalog::XAHAU;

    /** Not a ChainCatalog constant: the descriptor comes from the package. */
    public const INTERLEDGER = 'INTERLEDGER';

    /** @var list<ChainDescriptor>|null */
    private static ?array $chains = null;

    /** @return list<ChainDescriptor> in the order an adapter should show them */
    public static function chains(): array
    {
        if (self::$chains !== null) {
            return self::$chains;
        }

        $chains = ChainCatalog::chains();

        if (class_exists(InterledgerChain::class)) {
            $chains[] = InterledgerChain::descriptor();
        }

        return self::$chains = $chains;
    }

    public static function has(string $chain): bool
    {
        foreach (self::chains() as $descriptor) {
            if ($descriptor->chain === $chain) {
                return true;
            }
        }

        return false;
    }

    public static function chain(string $chain): ChainDescriptor
    {
        foreach (self::chains() as $descriptor) {
            if ($descriptor->chain === $chain) {
                return $descriptor;
            }
        }

        throw new InvalidArgumentException("Unknown chain '{$chain}'.");
    }

    /**
     * The chain and asset that write a given intent `type`, or null.
     *
     * @return array{0: ChainDescriptor, 1: AssetDescriptor}|null
     */
    public static function forType(string $type): ?array
    {
        foreach (self::chains() as $chain) {
            foreach ($chain->assets as $asset) {
                if ($asset->type === $type) {
                    return [$chain, $asset];
                }
            }
        }

        return null;
    }

    /**
     * The account as it should be stored for this chain.
     *
     * Only Interledger has anything to normalise: a wallet address may be
     * written as a payment pointer (`$ilp.example.com/acme`), which is input
     * syntax for the https URL and is stored as one. A ledger account is
     * returned as it came in.
     */
    public static function normalizeAccount(string $chain, string $account): string
    {
        if ($chain !== self::INTERLEDGER || !class_exists(InterledgerChain::class)) {
            return $account;
        }

        return InterledgerChain::normalizeWalletAddress($account);
    }

    /**
     * Test seam: forget the memoised list. The chains are static data, so
     * nothing in a request changes them — but a test that runs with and
     * without the Interledger package has to be able to say so.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$chains = null;
    }
}
