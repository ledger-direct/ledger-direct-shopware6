<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\Stellar\Schema;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The two Stellar tables, `ledger_direct_stellar_payment` and
 * `ledger_direct_stellar_memo`, as core 0.10 describes them (INVARIANTS.md,
 * "Stellar", Tables). The DDL comes from the core, not from here: the core
 * pins it by test, and every LedgerDirect installation is meant to carry the
 * same table. `CREATE TABLE IF NOT EXISTS`, so the step is idempotent.
 */
class Migration1793112262StellarTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793112262;
    }

    public function update(Connection $connection): void
    {
        foreach (Schema::mysql() as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
