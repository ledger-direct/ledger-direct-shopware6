<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Presentation\Explorer;
use PHPUnit\Framework\TestCase;

/**
 * A chain is more than its handler: the payment page looks its sentences up
 * by the chain's name, the checkout its icon by the asset's, the configuration
 * its keys by both. None of that fails when a file is forgotten — a missing
 * snippet renders as its key, a missing icon as a broken image, a missing
 * configuration field as a chain nobody can switch on. So every chain the
 * plugin handles is checked against the files here.
 */
class ChainResourcesTest extends TestCase
{
    private const RESOURCES = __DIR__ . '/../../../src/Resources';

    private const LOCALES = ['en-GB', 'de-DE'];

    public function testEveryHandledChainHasItsSentencesOnThePaymentPage(): void
    {
        foreach (self::LOCALES as $locale) {
            $page = self::json('/snippet/storefront/ledger-direct.' . $locale . '.json')['LedgerDirect']['paymentPage'];
            $identifierKeys = array_keys($page['identifier']['XRPL']);

            foreach (PaymentMethodInstaller::supportedChains() as $chain) {
                $this->assertSame($identifierKeys, array_keys($page['identifier'][$chain->chain] ?? []), "{$locale}: identifier.{$chain->chain}");
                $this->assertSame($chain->networks, array_keys($page['network'][$chain->chain] ?? []), "{$locale}: network.{$chain->chain}");
                $this->assertNotSame('', $page['poweredBy'][$chain->chain] ?? '', "{$locale}: poweredBy.{$chain->chain}");
            }
        }
    }

    public function testEveryHandledChainNamesItsIdentifierOnTheOrderCard(): void
    {
        foreach (self::LOCALES as $locale) {
            $identifier = self::json('/app/administration/src/snippet/' . $locale . '.json')['ledger-direct']['paymentCard']['identifier'];

            foreach (PaymentMethodInstaller::supportedChains() as $chain) {
                $this->assertNotSame('', $identifier[$chain->chain] ?? '', "{$locale}: paymentCard.identifier.{$chain->chain}");
            }
        }
    }

    /**
     * The checkout picks the icon by the last part of the technical name,
     * `ledgerdirect_<chain>_<asset>` (payment-method.html.twig).
     */
    public function testEveryAssetHasItsCheckoutIcon(): void
    {
        foreach (PaymentMethodInstaller::paymentMethods() as $method) {
            $asset = substr((string) $method['technicalName'], (int) strrpos((string) $method['technicalName'], '_') + 1);

            $this->assertFileExists(self::RESOURCES . '/public/storefront/img/' . $asset . '_payment.svg', $method['technicalName']);
        }
    }

    /**
     * The keys ConfigurationService reads by name: the switch, the network,
     * an account per network, a toggle per issued asset.
     */
    public function testEveryHandledChainHasItsConfigurationCard(): void
    {
        $xml = simplexml_load_file(self::RESOURCES . '/config/config.xml');
        $this->assertNotFalse($xml);
        $names = array_map('strval', $xml->xpath('//name'));

        foreach (PaymentMethodInstaller::supportedChains() as $chain) {
            $key = strtolower($chain->chain);
            $expected = [$key . 'Enabled', 'use' . ucfirst($key) . 'Testnet'];

            foreach ($chain->networks as $network) {
                $expected[] = $key . ucfirst($network) . 'DestinationAccount';
            }

            foreach ($chain->assets as $asset) {
                if (!$asset->native) {
                    $expected[] = $key . 'Is' . ucfirst(strtolower($asset->code)) . 'Enabled';
                }
            }

            foreach ($expected as $name) {
                $this->assertContains($name, $names, "config.xml: {$name}");
            }
        }
    }

    public function testEveryHandledChainHasAnExplorerAndIsInTheStoreApiSchema(): void
    {
        $schema = file_get_contents(self::RESOURCES . '/Schema/StoreApi/paths/ledger-direct-payment.json');
        $this->assertNotFalse($schema);

        foreach (PaymentMethodInstaller::supportedChains() as $chain) {
            foreach ($chain->networks as $network) {
                $this->assertStringStartsWith('https://', Explorer::transactionBase($chain->chain, $network), "{$chain->chain} {$network}");
            }

            $this->assertStringContainsString('"' . $chain->chain . '"', $schema, 'Store API schema: chain enum');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $path): array
    {
        return json_decode((string) file_get_contents(self::RESOURCES . $path), true, 512, JSON_THROW_ON_ERROR);
    }
}
