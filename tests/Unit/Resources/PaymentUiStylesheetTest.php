<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Shopware compiles the plugin's SCSS on the server, where node_modules does
 * not exist, so the package's stylesheet is committed as a copy under scss/.
 * This test keeps the copy honest: it must be the package's file, byte for
 * byte, whenever the package is installed locally.
 */
class PaymentUiStylesheetTest extends TestCase
{
    private const STOREFRONT = __DIR__ . '/../../../src/Resources/app/storefront/src';

    public function testTheCommittedStylesheetIsThePackagesOwn(): void
    {
        $copy = self::STOREFRONT . '/scss/payment-page.css';
        $package = self::STOREFRONT . '/node_modules/@ledger-direct/payment-ui/src/payment-page.css';

        $this->assertFileExists($copy);
        $this->assertStringContainsString('.ld-page', (string) file_get_contents($copy));

        if (!is_file($package)) {
            $this->markTestSkipped('@ledger-direct/payment-ui is not installed here; run npm install in the storefront directory to compare');
        }

        $this->assertFileEquals($package, $copy, 'scss/payment-page.css differs from the package — run `npm run sync` in the storefront directory');
    }
}
