<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Aligns the plugin schema with what hardcastle/ledger-direct-core expects
 * (see INVARIANTS.md in the core repo).
 *
 * - `destination_tag` becomes INT UNSIGNED: XRPL's DestinationTag is an
 *   unsigned 32-bit field, and the core's DestinationTagService uses that
 *   full range (up to 4294967295). The previous signed INT(11) silently
 *   truncates or rejects everything above 2147483647 — and a truncated tag
 *   means the customer's payment is never matched to their order.
 * - `ledger_index` becomes BIGINT UNSIGNED: it is read via MAX() to resume
 *   syncing, and MAX() over a VARCHAR sorts lexicographically ("9" > "10"),
 *   so the sync would resume at the wrong point on the next digit rollover.
 * - `ledger_direct_xrpl_destination_tag` changes from a list of issued tags
 *   to a per-account counter, which is what the core's repository port
 *   increments atomically.
 */
class Migration1788434149CoreSchemaAlignment extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788434149;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            ALTER TABLE `ledger_direct_xrpl_tx`
            MODIFY `destination_tag` INT UNSIGNED DEFAULT NULL
        ');

        $connection->executeStatement('
            ALTER TABLE `ledger_direct_xrpl_tx`
            MODIFY `ledger_index` BIGINT UNSIGNED NOT NULL
        ');

        if (!$this->indexExists($connection, 'ledger_direct_xrpl_tx', 'idx.ledger_direct_xrpl_tx.destination')) {
            $connection->executeStatement('
                ALTER TABLE `ledger_direct_xrpl_tx`
                ADD INDEX `idx.ledger_direct_xrpl_tx.destination` (`destination`, `destination_tag`)
            ');
        }

        /*
         * Guarded so a re-run cannot reset the counters: dropping a live
         * counter table would hand out destination tags that were already
         * issued. Replacing the old "one row per issued tag" table is safe
         * here only because this plugin has never been released — see the
         * retrofit handover for the migration path a live install would need.
         */
        if (!$this->isCounterTable($connection, 'ledger_direct_xrpl_destination_tag')) {
            $this->dropTableIfExists($connection, 'ledger_direct_xrpl_destination_tag');
            $connection->executeStatement('
                CREATE TABLE `ledger_direct_xrpl_destination_tag` (
                    `destination_account`   VARCHAR(64) NOT NULL,
                    `sequence`              INT UNSIGNED NOT NULL,

                    PRIMARY KEY (`destination_account`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * Whether the destination tag table has already been converted from the
     * old list of issued tags to the per-account counter.
     */
    private function isCounterTable(Connection $connection, string $table): bool
    {
        $schemaManager = $connection->createSchemaManager();

        if (!$schemaManager->tablesExist([$table])) {
            return false;
        }

        foreach ($schemaManager->listTableColumns($table) as $column) {
            if ($column->getName() === 'sequence') {
                return true;
            }
        }

        return false;
    }
}
