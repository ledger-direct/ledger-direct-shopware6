<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Shopware renders a plugin's template only where the storefront includes
 * the same path. An override that extends a storefront template must sit at
 * that template's path; anywhere else it is never loaded. The checkout icon
 * lived under page/payment/ and never showed, because the storefront includes
 * component/payment/payment-method.html.twig.
 *
 * The plugin's own pages under ledger-direct/ extend the base layout and are
 * rendered by name, so they are exempt.
 */
class StorefrontTemplateOverrideTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../../src/Resources/views';

    public function testAnOverrideSitsAtThePathItExtends(): void
    {
        $overrides = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen(self::VIEWS) + 1);
            if (!str_ends_with($path, '.html.twig') || str_starts_with($path, 'storefront/ledger-direct/')) {
                continue;
            }

            $extends = preg_match('#\{%\s*sw_extends\s+\'@Storefront/(?<path>[^\']+)\'#', (string) file_get_contents($file->getPathname()), $match);
            $this->assertSame(1, $extends, "{$path} extends no storefront template");
            $this->assertSame($match['path'], $path, "{$path} extends {$match['path']} and is never loaded");
            ++$overrides;
        }

        $this->assertGreaterThan(0, $overrides);
    }

    public function testTheCheckoutIconOverridesThePaymentMethodComponent(): void
    {
        $this->assertFileExists(self::VIEWS . '/storefront/component/payment/payment-method.html.twig');
    }
}
