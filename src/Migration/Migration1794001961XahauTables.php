<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Xrpl\Schema;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The two Xahau tables, `ledger_direct_xahau_tx` and
 * `ledger_direct_xahau_destination_tag`, as core 0.11 describes them
 * (INVARIANTS.md, "Xahau", Tables): the XRPL tables' columns under names
 * of their own. Xahau shares no row and no tag counter with the XRP Ledger,
 * even for an account that exists on both. The DDL comes from the core.
 * `CREATE TABLE IF NOT EXISTS`, so the step is idempotent.
 */
class Migration1794001961XahauTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794001961;
    }

    public function update(Connection $connection): void
    {
        foreach (Schema::mysql(chain: ChainCatalog::XAHAU) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
