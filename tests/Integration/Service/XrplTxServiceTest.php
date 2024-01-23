<?php declare(strict_types=1);

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
            'dbname' => 'mydb',
            'user' => 'user',
            'password' => 'secret',
            'host' => 'localhost',
            'driver' => 'pdo_mysql',
        ];
        $this->connection = DriverManager::getConnection($connectionParams);

        $val =getenv('MY_ENV_VAR');

        $this->clientService = new XrplClientService(/* Add necessary dependencies here */);
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