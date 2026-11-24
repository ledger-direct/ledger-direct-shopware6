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
}
