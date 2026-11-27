<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Shopware's administration has no global date filter: a component that
 * formats a date in its template has to bring `dateFilter` itself. The
 * payment card called it without having it, and whenever the row with the
 * quote's validity was due, the card stopped at the state label and an error
 * in the browser console was all there was to see.
 */
class AdministrationTemplateTest extends TestCase
{
    private const COMPONENTS = __DIR__ . '/../../../src/Resources/app/administration/src/component';

    public function testAComponentThatFormatsADateHasTheFilter(): void
    {
        $checked = 0;

        foreach ((array) glob(self::COMPONENTS . '/*', GLOB_ONLYDIR) as $component) {
            if (!str_contains((string) file_get_contents($component . '/template.html.twig'), 'dateFilter(')) {
                continue;
            }

            $this->assertStringContainsString(
                'dateFilter()',
                (string) file_get_contents($component . '/index.js'),
                basename((string) $component) . ': the template calls dateFilter(), the component does not define it'
            );
            ++$checked;
        }

        $this->assertGreaterThan(0, $checked, 'no component with a date found; has the test lost its subject?');
    }

    /**
     * The "Refresh" button of the received payments: it calls the component's
     * refresh(), and is disabled while a request runs.
     */
    public function testTheReceivedPaymentsCardHasItsRefreshButton(): void
    {
        $template = (string) file_get_contents(self::COMPONENTS . '/ledger-direct-received-payments/template.html.twig');

        $this->assertSame(1, preg_match('/<mt-button\b[^>]*>\s*\{\{ \$tc\(\'ledger-direct\.receivedPayments\.refresh\'\) \}\}/', $template, $button), 'no button with the refresh label');
        $this->assertStringContainsString('@click="refresh"', $button[0]);
        $this->assertStringContainsString(':disabled="isLoading"', $button[0]);
        $this->assertSame(1, preg_match('/<mt-banner\b[^>]*v-if="ledgerUnreachable"[^>]*>\s*\{\{ \$tc\(\'ledger-direct\.receivedPayments\.ledgerUnreachable\'\) \}\}/', $template), 'no notice for a ledger that could not be reached');
    }

    /**
     * After a refresh the payment card above loads again: the list tells the
     * page, and the page has the card to tell. What the two do with it,
     * administration-load-order.mjs runs.
     */
    public function testThePageHandsARefreshToThePaymentCard(): void
    {
        $page = (string) file_get_contents(self::COMPONENTS . '/../view/sw-order-detail-details/template.html.twig');

        $this->assertSame(1, preg_match('/<ledger-direct-received-payments\b[^>]*@refreshed="onLedgerDirectPaymentsRefreshed"/', $page), 'the list\'s refresh is not heard');
        $this->assertSame(1, preg_match('/<ledger-direct-payment-card\b[^>]*ref="ledgerDirectPaymentCard"/', $page), 'the payment card cannot be reached');
    }
}
