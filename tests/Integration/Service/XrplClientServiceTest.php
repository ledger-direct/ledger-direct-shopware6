<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Integration\Service;

use Hardcastle\LedgerDirect\Service\XrplClientService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use PHPUnit\Framework\TestCase;

class XrplClientServiceTest extends TestCase
{
    private XrplClientService $xrplClientService;

    public function setUp(): void
    {
        $configValues = Fixtures::getIntegrationTestConfiguration();
        $configurationService = ConfigurationServiceMock::createInstance($configValues);
        $this->xrplClientService = new XrplClientService($configurationService);
    }
    public function testFetchAccountTransactions(): void
    {
        $transactions = $this->xrplClientService->fetchAccountTransactions(getenv('LEDGER_DIRECT_TEST_XRPL_ADDRESS'), null);
        $this->assertCount(1, $transactions);
    }
}
