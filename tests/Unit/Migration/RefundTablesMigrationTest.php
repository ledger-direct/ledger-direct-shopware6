<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Migration\Migration1795795269RefundTables;
use PHPUnit\Framework\TestCase;

/**
 * The refund tables are a record: the step that creates them may run over
 * rows that are already there (an uninstall forgets the plugin's migrations,
 * the next install runs them again) and must leave them alone.
 */
class RefundTablesMigrationTest extends TestCase
{
    public function testItCreatesBothRefundTablesAndOnlyIfTheyAreMissing(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 0;
        });

        (new Migration1795795269RefundTables())->update($connection);

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ledger_direct_xrpl_refund` (', $statements[0]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ledger_direct_xahau_refund` (', $statements[1]);
        foreach ($statements as $statement) {
            $this->assertSame(4, substr_count($statement, 'UNIQUE KEY'), 'the four rules only storage can hold');
        }
    }

    public function testItsDestructivePartDoesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method($this->anything());

        (new Migration1795795269RefundTables())->updateDestructive($connection);
    }
}
