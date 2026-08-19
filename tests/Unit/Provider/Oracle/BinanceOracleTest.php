<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\Oracle\BinanceOracle;
use Mockery;
use PHPUnit\Framework\TestCase;

class BinanceOracleTest extends TestCase
{
    private BinanceOracle $oracle;

    private $client;

    protected function setUp(): void
    {
        $this->client = Mockery::mock(Client::class);
        $this->oracle = (new BinanceOracle())->prepare($this->client);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * Binance has no direct USD pairs; a USD quote is queried against USDT instead.
     */
    public function testGetCurrentPriceForPairQueriesUsdtForUsdQuote(): void
    {
        $this->client->shouldReceive('get')
            ->with(Mockery::pattern('/symbol=XRPUSDT/'))
            ->andReturn(new Response(200, [], json_encode(['symbol' => 'XRPUSDT', 'price' => '1.00060000'])));

        $this->assertSame(1.0006, $this->oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testGetCurrentPriceForPairUsesNonUsdQuoteDirectly(): void
    {
        $this->client->shouldReceive('get')
            ->with(Mockery::pattern('/symbol=XRPEUR/'))
            ->andReturn(new Response(200, [], json_encode(['symbol' => 'XRPEUR', 'price' => '0.86390000'])));

        $this->assertSame(0.8639, $this->oracle->getCurrentPriceForPair('XRP', 'EUR'));
    }

    public function testGetCurrentPriceForPairReturnsZeroWhenPriceIsMissing(): void
    {
        $this->client->shouldReceive('get')->andReturn(new Response(200, [], '{}'));

        $this->assertSame(0.0, $this->oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    /**
     * An unlisted symbol returns HTTP 400 with an error body, not a 200 with a missing
     * "price" key. Guzzle's default http_errors setting turns that into a thrown
     * exception; the oracle itself doesn't catch it (@throws GuzzleException) -
     * the calling price provider is responsible for treating this oracle as
     * unavailable for the pair.
     */
    public function testGetCurrentPriceForPairThrowsForUnknownSymbol(): void
    {
        $this->client->shouldReceive('get')->andThrow(
            new ClientException(
                'Client error',
                new Request('GET', 'https://api.binance.com'),
                new Response(400, [], json_encode(['code' => -1121, 'msg' => 'Invalid symbol.']))
            )
        );

        $this->expectException(ClientException::class);

        $this->oracle->getCurrentPriceForPair('NOTACOIN', 'USD');
    }
}
