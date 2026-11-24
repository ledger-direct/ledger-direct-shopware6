<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The reference counter for Interledger wallet addresses,
 * `ledger_direct_interledger_reference`.
 *
 * The DDL is the package's (`Interledger\Schema::mysql()`), as the Stellar
 * tables take theirs from the core — but the package is optional, so unlike
 * the Stellar step this one carries the statement itself and runs whether or
 * not the package is installed. A table nobody writes to costs nothing; a
 * migration that is skipped on install and needed later costs a merchant a
 * failed checkout.
 *
 * Kept in sync with the package by a unit test, not by an import.
 * `CREATE TABLE IF NOT EXISTS`, so the step is idempotent.
 */
class Migration1793485162InterledgerReference extends MigrationStep
{
    public const SQL = 'CREATE TABLE IF NOT EXISTS `ledger_direct_interledger_reference` (
    `wallet_address` VARCHAR(255) NOT NULL,
    `sequence` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`wallet_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

    public function getCreationTimestamp(): int
    {
        return 1793485162;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(self::SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
