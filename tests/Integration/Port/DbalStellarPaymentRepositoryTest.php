<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Port;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\StellarPaymentRepositoryContractTestCase;
use Hardcastle\LedgerDirect\Port\DbalStellarPaymentRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * The core's repository contract against Shopware's database: the ordering,
 * the (hash, op_index) identity, the cursor per account and network, the
 * memo-id counter. The plugin adds nothing of its own to prove here.
 */
class DbalStellarPaymentRepositoryTest extends StellarPaymentRepositoryContractTestCase
{
    use IntegrationTestBehaviour; // wraps each test in a rolled-back DB transaction

    protected function repository(): StellarPaymentRepositoryInterface
    {
        /** @var Connection $connection */
        $connection = $this->getContainer()->get(Connection::class);

        // Caches and counters, inside the rolled-back transaction: start empty.
        $connection->executeStatement('DELETE FROM `ledger_direct_stellar_payment`');
        $connection->executeStatement('DELETE FROM `ledger_direct_stellar_memo`');

        return new DbalStellarPaymentRepository($connection);
    }
}
