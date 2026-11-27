<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundSchema;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The refund tables, `ledger_direct_xrpl_refund` and
 * `ledger_direct_xahau_refund`, as core 0.13 describes them (INVARIANTS.md,
 * "Refund", Tables). The DDL comes from the core, with the four unique
 * indexes that keep two simultaneous requests from refunding or assigning a
 * payment twice.
 *
 * The tables are a record, not a cache: they hold what is nowhere on the
 * ledger (which order a payment was for, who confirmed the destination,
 * whether the refund is booked). Nothing in this plugin empties or drops
 * them, uninstall included. `CREATE TABLE IF NOT EXISTS` is what lets them
 * outlive an uninstall: Shopware forgets the plugin's migrations then and
 * runs this step again on the next install, over the rows that are there.
 */
class Migration1795795269RefundTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795795269;
    }

    public function update(Connection $connection): void
    {
        foreach ([ChainCatalog::XRPL, ChainCatalog::XAHAU] as $chain) {
            foreach (RefundSchema::mysql(chain: $chain) as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
