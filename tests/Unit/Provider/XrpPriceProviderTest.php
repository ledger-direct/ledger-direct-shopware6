<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class XrpPriceProviderTest extends TestCase
{
    private XrpPriceProvider $xrpPriceProvider;

    private Client $client;

    protected function setUp(): void
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')
            ->andReturn('{"price": 0.5}');

        $this->client = Mockery::mock(Client::class);
        $this->client->shouldReceive('get')
            ->andReturn($response);

        $this->xrpPriceProvider = new XrpPriceProvider($this->client);
    }
    public function testGetCurrentExchangeRate(): void
    {
        $this->assertEquals(0.5, $this->xrpPriceProvider->getCurrentExchangeRate('USD'));
        $this->assertEquals(0.5, $this->xrpPriceProvider->getCurrentExchangeRate('EUR'));
    }

    public function testCheckPricePlausibility(): void
    {
        $this->assertTrue($this->xrpPriceProvider->checkPricePlausibility(0.5));
        $this->assertFalse($this->xrpPriceProvider->checkPricePlausibility(0.0));
    }
}