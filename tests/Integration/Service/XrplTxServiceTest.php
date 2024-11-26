<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Service;

use Hardcastle\LedgerDirect\Service\XrplTxService;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Service\XrplClientService;

class XrplTxServiceTest extends TestCase
{
    private XrplTxService $xrplTxService;
    private XrplClientService $clientService;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(XrplClientService::class);
        $this->connection = $this->createMock(Connection::class);
        $this->xrplTxService = new XrplTxService($this->clientService, $this->connection);
    }

    public function testGenerateDestinationTag(): void
    {
        $this->connection->expects($this->once())->method('insert');
        $destinationTag = $this->xrplTxService->generateDestinationTag();
        $this->assertIsInt($destinationTag);
        $this->assertGreaterThanOrEqual(XrplTxService::DESTINATION_TAG_RANGE_MIN, $destinationTag);
        $this->assertLessThanOrEqual(XrplTxService::DESTINATION_TAG_RANGE_MAX, $destinationTag);
    }
}

/*
namespace Hardcastle\LedgerDirect\Tests\Integration\Service;

use Doctrine\DBAL\DriverManager;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Service\XrplClientService;

class XrplTxServiceTest extends TestCase
{
    private XrplTxService $xrplTxService;
    private XrplClientService $clientService;
    private Connection $connection;

    protected function setUp(): void
    {
        // Replace with your actual database connection parameters
        $connectionParams = [
            'dbname' => 'shopware_test',
            'user' => 'root',
            'password' => 'password',
            'host' => '127.0.0.1',
            'driver' => 'pdo_mysql',
        ];
        $this->connection = DriverManager::getConnection($connectionParams);

        $val = getenv('MY_ENV_VAR');

        $this->clientService = new XrplClientService();
        $this->xrplTxService = new XrplTxService($this->clientService, $this->connection);
    }

    public function testGenerateDestinationTagIntegration(): void
    {
        $destinationTag = $this->xrplTxService->generateDestinationTag();

        $this->assertIsInt($destinationTag);
        $this->assertGreaterThanOrEqual(XrplTxService::DESTINATION_TAG_RANGE_MIN, $destinationTag);
        $this->assertLessThanOrEqual(XrplTxService::DESTINATION_TAG_RANGE_MAX, $destinationTag);

        $statement = $this->connection->executeQuery(
            'SELECT destination_tag FROM xrpl_destination_tag WHERE destination_tag = :destination_tag',
            ['destination_tag' => $destinationTag],
            ['destination_tag' => PDO::PARAM_INT]
        );
        $matches = $statement->fetchAllAssociative();

        $this->assertNotEmpty($matches, 'Destination tag not found in the database');
    }
}

*/