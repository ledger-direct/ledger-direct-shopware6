<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1677049614XrplDestinationTag extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1677049614;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `xrpl_destination_tag` (
                `destination_tag`   INT(11) NOT NULL,
                
                PRIMARY KEY (`destination_tag`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
