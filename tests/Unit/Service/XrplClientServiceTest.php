<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Service\XrplClientService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use PHPUnit\Framework\TestCase;

class XrplClientServiceTest extends TestCase
{
    private XrplClientService $xrplClientService;

    public function setUp(): void
    {
        $configValues = Fixtures::getIntegrationTestConfiguration();
        $configurationService = ConfigurationServiceMock::createInstance($configValues);

        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')->andReturn(
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'result' => [
                    'status' => 'success',
                    'transactions' => [ ['tx' => ['hash' => 'ABC'], 'meta' => []] ]
                ],
                'id' => 1
            ]))
        );

        $this->xrplClientService = new XrplClientService($configurationService, $httpClient);
    }

    public function testFetchAccountTransactions(): void
    {
        $transactions = $this->xrplClientService->fetchAccountTransactions('rTest', null);
        $this->assertCount(1, $transactions);
    }

    public function testGetNetwork(): void
    {
        $network = $this->xrplClientService->getNetwork();
        $this->assertEquals('testnet', $network['network']);
        $this->assertArrayHasKey('jsonRpcUrl', $network);
    }
}
