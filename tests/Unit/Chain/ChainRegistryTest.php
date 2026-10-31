<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Chain;

use Hardcastle\LedgerDirect\Chain\ChainRegistry;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's list of chains: the core's catalogue plus Interledger, whose
 * descriptor comes from the package installed next to it.
 */
class ChainRegistryTest extends TestCase
{
    public function testTheCoresChainsAreAllThereInOrder(): void
    {
        $chains = array_map(static fn ($chain): string => $chain->chain, ChainRegistry::chains());

        $this->assertSame(['XRPL', 'STELLAR'], array_slice($chains, 0, 2), 'the catalogue first, in its order');
        $this->assertSame(
            array_map(static fn ($chain): string => $chain->chain, ChainCatalog::chains()),
            array_slice($chains, 0, count(ChainCatalog::chains())),
        );
    }

    public function testInterledgerJoinsWhenItsPackageIsInstalled(): void
    {
        $this->assertTrue(
            class_exists(\Hardcastle\LedgerDirect\Interledger\Chain::class),
            'the package is a dev dependency, so the suite runs with it',
        );

        $this->assertTrue(ChainRegistry::has('INTERLEDGER'));
        $this->assertSame('INTERLEDGER', ChainRegistry::chain('INTERLEDGER')->chain);
        $this->assertSame(['EUR', 'USD', 'XRP', 'DSUSD'], ChainRegistry::chain('INTERLEDGER')->assetCodes());
        $this->assertFalse(ChainCatalog::has('INTERLEDGER'), 'the core does not know it yet (handover §7, item 6)');
    }

    public function testAnIntentTypeFindsItsChainAndAsset(): void
    {
        [$chain, $asset] = ChainRegistry::forType('interledger-eur-payment');
        $this->assertSame(['INTERLEDGER', 'EUR'], [$chain->chain, $asset->code]);

        [$chain, $asset] = ChainRegistry::forType('stellar-usdc-payment');
        $this->assertSame(['STELLAR', 'USDC'], [$chain->chain, $asset->code]);

        $this->assertNull(ChainRegistry::forType('xahau-xah-payment'));
    }

    public function testAnUnknownChainIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ChainRegistry::chain('XAHAU');
    }

    public function testAnInterledgerPaymentPointerIsStoredAsItsUrl(): void
    {
        $this->assertSame(
            'https://ilp.example.test/acme',
            ChainRegistry::normalizeAccount('INTERLEDGER', '$ilp.example.test/acme/'),
        );
    }

    public function testALedgerAccountIsStoredAsItCameIn(): void
    {
        $this->assertSame('rMerchant', ChainRegistry::normalizeAccount('XRPL', 'rMerchant'));
        $this->assertSame('GMERCHANT', ChainRegistry::normalizeAccount('STELLAR', 'GMERCHANT'));
    }

    public function testTheInterledgerDescriptorAcceptsAWalletAddressOnly(): void
    {
        $descriptor = ChainRegistry::chain('INTERLEDGER');

        $this->assertTrue($descriptor->isValidAccount('https://ilp.interledger-test.dev/acme'));
        $this->assertFalse($descriptor->isValidAccount('$ilp.interledger-test.dev/acme'), 'normalised before it is checked');
        $this->assertFalse($descriptor->isValidAccount('rMerchant'));
    }
}
