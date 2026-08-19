<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1787126701RenameTablesAndHashIndex extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787126701;
    }

    public function update(Connection $connection): void
    {
        $this->renameIfNeeded($connection, 'xrpl_tx', 'ledger_direct_xrpl_tx');
        $this->renameIfNeeded($connection, 'xrpl_destination_tag', 'ledger_direct_xrpl_destination_tag');

        if (!$this->indexExists($connection, 'ledger_direct_xrpl_tx', 'uniq.ledger_direct_xrpl_tx.hash')) {
            $connection->executeStatement('
                DELETE t1 FROM `ledger_direct_xrpl_tx` t1
                INNER JOIN `ledger_direct_xrpl_tx` t2
                WHERE t1.`hash` = t2.`hash` AND t1.`id` > t2.`id`
            ');
            $connection->executeStatement('
                ALTER TABLE `ledger_direct_xrpl_tx`
                ADD UNIQUE INDEX `uniq.ledger_direct_xrpl_tx.hash` (`hash`)
            ');
        }
    }

    private function renameIfNeeded(Connection $connection, string $from, string $to): void
    {
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist([$from]) && !$schemaManager->tablesExist([$to])) {
            $connection->executeStatement(sprintf('RENAME TABLE `%s` TO `%s`', $from, $to));
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
