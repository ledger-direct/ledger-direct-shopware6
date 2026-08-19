<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;
use Mockery;
use PHPUnit\Framework\TestCase;

class CoingeckoOracleTest extends TestCase
{
    private CoingeckoOracle $oracle;

    private $client;

    protected function setUp(): void
    {
        $this->client = Mockery::mock(Client::class);
        $this->oracle = (new CoingeckoOracle())->prepare($this->client);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testGetCurrentPriceForPairMapsXrpToRippleId(): void
    {
        $this->client->shouldReceive('get')
            ->with(Mockery::pattern('/ids=ripple&vs_currencies=usd/'))
            ->andReturn(new Response(200, [], json_encode(['ripple' => ['usd' => 0.999978]])));

        $this->assertSame(0.999978, $this->oracle->getCurrentPriceForPair('XRP', 'USD'));
    }

    public function testGetCurrentPriceForPairMapsUsdcToUsdCoinId(): void
    {
        $this->client->shouldReceive('get')
            ->with(Mockery::pattern('/ids=usd-coin&vs_currencies=usd/'))
            ->andReturn(new Response(200, [], json_encode(['usd-coin' => ['usd' => 0.999742]])));

        $this->assertSame(0.999742, $this->oracle->getCurrentPriceForPair('USDC', 'USD'));
    }

    public function testGetCurrentPriceForPairMapsRlusdToRippleUsdId(): void
    {
        $this->client->shouldReceive('get')
            ->with(Mockery::pattern('/ids=ripple-usd&vs_currencies=usd/'))
            ->andReturn(new Response(200, [], json_encode(['ripple-usd' => ['usd' => 1.0]])));

        $this->assertSame(1.0, $this->oracle->getCurrentPriceForPair('RLUSD', 'USD'));
    }

    /**
     * Quote codes (fiat, e.g. 'EUR') aren't in the mapping table and fall back to their
     * lowercased literal - Coingecko's vs_currencies param happens to accept ISO codes
     * as-is, so this isn't a bug, just the mapping table's designed behaviour.
     */
    public function testGetCurrentPriceForPairFallsBackToLowercasedCodeWhenUnmapped(): void
    {
        $this->client->shouldReceive('get')
            ->with(Mockery::pattern('/ids=ripple&vs_currencies=eur/'))
            ->andReturn(new Response(200, [], json_encode(['ripple' => ['eur' => 0.86]])));

        $this->assertSame(0.86, $this->oracle->getCurrentPriceForPair('XRP', 'EUR'));
    }

    /**
     * S6 (latent): EURC has no mapping entry yet and falls back to the literal "eurc",
     * which Coingecko doesn't recognise - an empty response, silently priced as 0.0.
     * Not fixed here since Shopware doesn't offer EURC as a payment method yet; this
     * documents the current fallback behaviour so a future EURC mapping addition has
     * a regression test to update.
     */
    public function testGetCurrentPriceForPairReturnsZeroWhenIdIsUnrecognised(): void
    {
        $this->client->shouldReceive('get')->andReturn(new Response(200, [], '{}'));

        $this->assertSame(0.0, $this->oracle->getCurrentPriceForPair('EURC', 'USD'));
    }
}
