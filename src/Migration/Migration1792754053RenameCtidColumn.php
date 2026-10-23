<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Renames `ctid` to `tx_ctid` on the synced-transaction table, the column
 * name core 0.9 fixes for every adapter (INVARIANTS.md, "Tables").
 *
 * PostgreSQL gives every table a system column called `ctid`, and no user
 * column may take that name, quoted or not: a table with one cannot be
 * created there. The core therefore stores the CTID in `tx_ctid`, and the
 * plugins follow so that every LedgerDirect installation has the same
 * table. The payment record keeps its `ctid` field; only the column moves.
 *
 * Idempotent: a table that already carries `tx_ctid` is left alone.
 */
class Migration1792754053RenameCtidColumn extends MigrationStep
{
    private const TABLE = 'ledger_direct_xrpl_tx';

    public function getCreationTimestamp(): int
    {
        return 1792754053;
    }

    public function update(Connection $connection): void
    {
        if ($this->columnExists($connection, self::TABLE, 'tx_ctid')) {
            return;
        }

        $connection->executeStatement('
            ALTER TABLE `' . self::TABLE . '`
            CHANGE COLUMN `ctid` `tx_ctid` VARCHAR(16) NOT NULL
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
