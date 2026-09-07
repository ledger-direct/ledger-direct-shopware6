<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds the `network` column the sync cursor is scoped by.
 *
 * The cursor used to be a global `MAX(ledger_index)`, which only works while
 * a shop has ever talked to one network. A single mainnet row — index around
 * 100 million — pins the cursor above every testnet ledger, and from then on
 * the testnet sync asks for a range no node can serve and silently returns
 * nothing, with no way out except editing the database by hand.
 */
class Migration1788801424NetworkColumn extends MigrationStep
{
    private const TABLE = 'ledger_direct_xrpl_tx';

    private const INDEX = 'idx.ledger_direct_xrpl_tx.destination_network';

    public function getCreationTimestamp(): int
    {
        return 1788801424;
    }

    public function update(Connection $connection): void
    {
        /*
         * columnExists() is inherited and throws on a missing table, which
         * cannot happen here: the table is created by Migration1667583695 and
         * renamed by Migration1787126701, both of which run before this one.
         */
        if (!$this->columnExists($connection, self::TABLE, 'network')) {
            $connection->executeStatement('
                ALTER TABLE `' . self::TABLE . '`
                ADD COLUMN `network` VARCHAR(16) NOT NULL DEFAULT \'\' AFTER `id`
            ');
        }

        /*
         * Backfilled from the CTID rather than from the shop's configured
         * network: a shop that tested on testnet and then went live has rows
         * from both, and guessing one network for all of them would poison
         * the very cursor this column exists to fix.
         *
         * The CTID is 'C' + 7 hex ledger index + 4 hex transaction index +
         * 4 hex network id, so the last four characters are the network:
         * 0 is mainnet, 1 is testnet.
         */
        $connection->executeStatement("
            UPDATE `" . self::TABLE . "`
               SET `network` = CASE CONV(RIGHT(`ctid`, 4), 16, 10)
                                 WHEN 0 THEN 'mainnet'
                                 WHEN 1 THEN 'testnet'
                                 ELSE ''
                               END
             WHERE `network` = '' AND CHAR_LENGTH(`ctid`) = 16
        ");

        if (!$this->indexExists($connection, self::TABLE, self::INDEX)) {
            $connection->executeStatement('
                ALTER TABLE `' . self::TABLE . '`
                ADD INDEX `' . self::INDEX . '` (`destination`, `network`, `ledger_index`)
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
