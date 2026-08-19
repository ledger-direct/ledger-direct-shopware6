<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class XrpPriceProviderTest extends TestCase
{
    private XrpPriceProvider $xrpPriceProvider;

    protected function setUp(): void
    {
        // Binance-style response ({"price":"..."}); the other oracles ignore this shape and are skipped.
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')->andReturn(new Response(200, [], '{"price":"0.5"}'));

        $this->xrpPriceProvider = new XrpPriceProvider($client, new NullLogger());
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

    public function testOracleFailureIsLogged(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->andThrow(new ConnectException('connection refused', new Request('GET', 'http://example.test')));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->times(3) // one per oracle (Binance, Coingecko, Kraken)
            ->with('LedgerDirect: price oracle failed', Mockery::on(
                fn (array $context) => $context['base'] === XrpPriceProvider::CRYPTO_CODE
                    && $context['quote'] === 'USD'
                    && $context['exception'] === 'connection refused'
            ));

        $xrpPriceProvider = new XrpPriceProvider($client, $logger);

        $this->assertFalse($xrpPriceProvider->getCurrentExchangeRate('USD'));
    }
}
