<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Port;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Interledger\Port\InterledgerRepositoryInterface;
use Hardcastle\LedgerDirect\Interledger\Testing\InterledgerRepositoryContractTestCase;
use Hardcastle\LedgerDirect\Port\DbalInterledgerRepository;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;

/**
 * The plugin's reference counter against the package's own contract: the
 * same suite the package's in-memory implementation passes.
 *
 * The counter is the point. It has to be atomic under concurrent checkouts
 * and start somewhere random per wallet address, and neither is visible from
 * the calling code — only from here, against a real MySQL.
 */
class DbalInterledgerRepositoryTest extends InterledgerRepositoryContractTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = KernelLifecycleManager::getKernel()->getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
    }

    protected function repository(): InterledgerRepositoryInterface
    {
        // A fresh counter per call: the contract's "starts somewhere random" case
        // builds several repositories and expects each wallet address to start once.
        $this->connection->executeStatement('DELETE FROM `ledger_direct_interledger_reference`');

        return new DbalInterledgerRepository($this->connection);
    }
}
