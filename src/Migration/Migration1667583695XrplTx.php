<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1667583695XrplTx extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1667583695;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `xrpl_tx` (
                `id`                BINARY(16) NOT NULL,
                `ledger_index`      VARCHAR(64) NOT NULL,
                `hash`              VARCHAR(64) NOT NULL,
                `ctid`              VARCHAR(16) NOT NULL,
                `account`           VARCHAR(35) NOT NULL,
                `destination`       VARCHAR(35) NOT NULL,
                `destination_tag`   INT(11) DEFAULT NULL,
                `date`              INT(11) NOT NULL,
                `meta`              TEXT NOT NULL,
                `tx`                TEXT NOT NULL,
                
                PRIMARY KEY (`id`)
            )  ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
