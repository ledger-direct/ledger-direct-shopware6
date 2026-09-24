<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * payment-ui/ is the payment page for every LedgerDirect plugin and moves
 * into a shared package once a second platform uses it. Until then this
 * test is the boundary: nothing in there may lean on Shopware's storefront
 * runtime, only on fetch() and the DOM.
 */
class PaymentUiIsolationTest extends TestCase
{
    private const PAYMENT_UI_DIRECTORY = __DIR__ . '/../../../src/Resources/app/storefront/src/payment-ui';

    /** Identifiers that would tie the directory to Shopware or a framework. */
    private const FORBIDDEN = [
        'PluginManager',
        'PluginBaseClass',
        'DomAccess',
        'HttpClient',
        "from 'src/",
        'from "src/',
        'jQuery',
    ];

    public function testThePaymentUiImportsNothingFromShopware(): void
    {
        $files = glob(self::PAYMENT_UI_DIRECTORY . '/*.js');

        $this->assertNotEmpty($files, 'payment-ui/ has no scripts');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    basename($file) . " must not mention '{$needle}'"
                );
            }

            // Relative modules, or one of the framework-free libraries the page needs.
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*import\s.*from\s+[\'"](?!\.|qrcode-generator|xrpl-connect)/m',
                $source,
                basename($file) . ' may only import relative modules or an allowed library'
            );
        }
    }

    public function testTheMarkupContractIsDocumented(): void
    {
        $readme = (string) file_get_contents(self::PAYMENT_UI_DIRECTORY . '/README.md');

        foreach (['data-ld-state', 'data-ld-poll-url', 'data-ld-block', 'data-ld-paid', 'data-ld-shortfall', 'data-copy'] as $anchor) {
            $this->assertStringContainsString($anchor, $readme, "README.md does not document {$anchor}");
        }
    }
}
