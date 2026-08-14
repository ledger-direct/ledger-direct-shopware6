<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Hardcastle\LedgerDirect\Service\XrplClientService;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class XrplTxServiceTest extends TestCase
{
    use IntegrationTestBehaviour; // wraps each test in a rolled-back DB transaction

    private XrplTxService $xrplTxService;

    private Connection $connection;

    protected function setUp(): void
    {
        $configurationService = ConfigurationServiceMock::createInstance(Fixtures::getIntegrationTestConfiguration());
        $clientService = new XrplClientService($configurationService, $this->getContainer()->get('shopware.app_system.guzzle'));
        $this->connection = $this->getContainer()->get(Connection::class);
        $this->xrplTxService = new XrplTxService($clientService, $this->connection);
    }

    public function testGenerateDestinationTag(): void
    {
        $destinationTag = $this->xrplTxService->generateDestinationTag();

        $matches = $this->connection->executeQuery(
            'SELECT destination_tag FROM xrpl_destination_tag WHERE destination_tag = :destination_tag',
            ['destination_tag' => $destinationTag],
            ['destination_tag' => ParameterType::INTEGER]
        )->fetchAllAssociative();

        $this->assertCount(1, $matches);
    }
}
