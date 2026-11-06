<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use PHPUnit\Framework\TestCase;

/**
 * The administration's part of the plugin ships built, under
 * `Resources/public/administration`: Shopware 6.7 reads `.vite/entrypoints.json`
 * there and loads the files it names. A build committed without its manifest,
 * or a snippet changed without a build, fails nowhere — the order card is
 * simply missing, or shows a snippet key. 1.7.0 to 1.9.0 shipped a manifest
 * that named a file the release did not contain.
 */
class AdministrationBuildTest extends TestCase
{
    private const BUILD = __DIR__ . '/../../../src/Resources/public/administration';

    private const BUNDLE_BASE = '/bundles/ledgerdirect/administration/';

    public function testTheEntryPointsNameFilesThatAreShipped(): void
    {
        $entry = self::json('/.vite/entrypoints.json')['entryPoints']['ledger-direct'];
        $files = [...$entry['js'], ...$entry['css']];
        $this->assertNotEmpty($entry['js']);

        foreach ($files as $url) {
            $this->assertStringStartsWith(self::BUNDLE_BASE, $url);
            $this->assertFileExists(self::BUILD . '/' . substr($url, strlen(self::BUNDLE_BASE)), $url);
        }

        $manifest = self::json('/.vite/manifest.json')['main.js'];
        $this->assertFileExists(self::BUILD . '/' . $manifest['file']);
        $this->assertSame(self::BUNDLE_BASE . $manifest['file'], $entry['js'][0], 'manifest and entry points name the same script');
    }

    /**
     * The snippets are compiled into the script. A chain added to the source
     * snippets without a new build would show its key on the order card.
     */
    public function testTheBuiltScriptKnowsEveryHandledChainsIdentifier(): void
    {
        $script = (string) file_get_contents(self::BUILD . '/' . self::json('/.vite/manifest.json')['main.js']['file']);

        foreach (['en-GB', 'de-DE'] as $locale) {
            $source = json_decode(
                (string) file_get_contents(__DIR__ . '/../../../src/Resources/app/administration/src/snippet/' . $locale . '.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            )['ledger-direct']['paymentCard']['identifier'];

            foreach (PaymentMethodInstaller::supportedChains() as $chain) {
                $this->assertStringContainsString(
                    $chain->chain . ':"' . $source[$chain->chain] . '"',
                    $script,
                    "{$locale}: identifier.{$chain->chain} is not in the built script; rebuild the administration"
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $path): array
    {
        return json_decode((string) file_get_contents(self::BUILD . $path), true, 512, JSON_THROW_ON_ERROR);
    }
}
