<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Mockery;
use PHPUnit\Framework\TestCase;

class XrpPriceProviderTest extends TestCase
{
    private XrpPriceProvider $xrpPriceProvider;

    protected function setUp(): void
    {
        // Binance-style response ({"price":"..."}); the other oracles ignore this shape and are skipped.
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn(new Response(200, [], '{"price":"0.5"}'));

        $this->xrpPriceProvider = new XrpPriceProvider($client);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testGetCurrentExchangeRate(): void
    {
        $this->assertSame(0.5, $this->xrpPriceProvider->getCurrentExchangeRate('USD'));
        $this->assertSame(0.5, $this->xrpPriceProvider->getCurrentExchangeRate('EUR'));
    }

    public function testCheckPricePlausibility(): void
    {
        $this->assertTrue($this->xrpPriceProvider->checkPricePlausibility(0.5));
        $this->assertFalse($this->xrpPriceProvider->checkPricePlausibility(0.0));
    }
}
