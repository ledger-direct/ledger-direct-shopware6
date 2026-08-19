<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\Oracle\KrakenOracle;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KrakenOracleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * Kraken's own pair naming is inconsistent - "XXRPZUSD" for XRP/USD, but plain
     * "USDCUSD" for USDC/USD. The oracle used to hardcode "XXRPZUSD", silently returning
     * 0.0 for every other pair. Regression test for reading whichever single key a
     * Ticker response actually comes back under, instead of predicting it.
     */
    #[DataProvider('pairResponseProvider')]
    public function testGetCurrentPriceForPairReadsAnyPairKey(string $body, float $expectedPrice): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn(new Response(200, [], $body));

        $oracle = (new KrakenOracle())->prepare($client);

        $this->assertSame($expectedPrice, $oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public static function pairResponseProvider(): array
    {
        return [
            'XRP/USD' => [
                '{"error":[],"result":{"XXRPZUSD":{"c":["0.52340000","100.00000000"]}}}',
                0.5234,
            ],
            'XRP/EUR' => [
                '{"error":[],"result":{"XXRPZEUR":{"c":["0.48120000","50.00000000"]}}}',
                0.4812,
            ],
            'USDC/USD' => [
                '{"error":[],"result":{"USDCUSD":{"c":["0.99980000","1000.00000000"]}}}',
                0.9998,
            ],
        ];
    }

    public function testGetCurrentPriceForPairReturnsZeroWhenResultIsEmpty(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn(new Response(200, [], '{"error":["EQuery:Unknown asset pair"],"result":{}}'));

        $oracle = (new KrakenOracle())->prepare($client);

        $this->assertSame(0.0, $oracle->getCurrentPriceForPair('XRP', 'XYZ'));
    }

    /**
     * Real Kraken error responses for an unknown pair omit "result" entirely
     * (only "error" is present) - not the same shape as an empty object.
     */
    public function testGetCurrentPriceForPairReturnsZeroWhenResultKeyIsMissing(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn(new Response(200, [], '{"error":["EQuery:Unknown asset pair"]}'));

        $oracle = (new KrakenOracle())->prepare($client);

        $this->assertSame(0.0, $oracle->getCurrentPriceForPair('NOTAPAIR', 'XYZ'));
    }
}
