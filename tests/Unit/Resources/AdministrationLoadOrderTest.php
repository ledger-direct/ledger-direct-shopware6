<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * The cards on the order detail load again when their prop changes, so two
 * requests can be under way and the older one can answer last. Its answer is
 * for the order or transaction shown before: written unchecked, it put that
 * one's payments under the order now on the screen, or cleared the rows that
 * had just arrived.
 *
 * Whether a component holds against that is what its load() does with
 * answers in the wrong order, and reading the source for a pattern would not
 * say. So the components' own code is run: administration-load-order.mjs takes
 * each definition from its index.js and drives created(), the watcher and
 * load() with answers it settles in the order it chooses, on plain Node,
 * without a browser or a package. A component that gets a watcher and is not
 * part of that run fails it.
 *
 * Skipped where Node is missing; the dockware container the suite runs in
 * (locally and in CI) has it.
 */
class AdministrationLoadOrderTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/administration-load-order.mjs';

    public function testALateAnswerForWhatWasShownBeforeChangesNothing(): void
    {
        if (!\function_exists('exec')) {
            $this->markTestSkipped('exec() is not available here; run `node ' . basename(self::SCRIPT) . '` by hand');
        }

        exec('node --version 2>&1', $version, $missing);

        if ($missing !== 0) {
            $this->markTestSkipped('node is not installed here; the load order of the administration components is checked where it is');
        }

        exec('node ' . escapeshellarg(self::SCRIPT) . ' 2>&1', $lines, $exit);
        $report = implode("\n", $lines);

        $this->assertSame(0, $exit, $report);
        $this->assertStringContainsString('ok      ledger-direct-received-payments: a late answer', $report, 'the run did not reach the received payments');
        $this->assertStringContainsString('ok      ledger-direct-payment-card: a late answer', $report, 'the run did not reach the payment card');
        $this->assertStringNotContainsString('FAILED', $report);
    }
}
