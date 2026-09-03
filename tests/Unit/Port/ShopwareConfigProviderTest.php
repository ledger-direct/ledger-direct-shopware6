<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Port;

use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;

class ShopwareConfigProviderTest extends TestCase
{
    private ShopwareConfigProvider $configProvider;

    protected function setUp(): void
    {
        $this->configProvider = new ShopwareConfigProvider(
            ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration())
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testGetNetwork(): void
    {
        $this->assertSame('testnet', $this->configProvider->getNetwork('XRPL'));
    }

    public function testGetDestinationAccount(): void
    {
        $this->assertSame(
            'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr',
            $this->configProvider->getDestinationAccount('XRPL')
        );
    }

    public function testGetQuoteExpirySeconds(): void
    {
        $this->assertSame(600, $this->configProvider->getQuoteExpirySeconds());
    }

    /**
     * XRP needs no trustline and no opt-in, so it is always on; the
     * stablecoins follow the plugin configuration (RLUSD on, USDC off in
     * the fixture); anything else the core might ask about is not accepted.
     */
    public function testIsAssetEnabled(): void
    {
        $this->assertTrue($this->configProvider->isAssetEnabled('XRPL', 'XRP'));
        $this->assertTrue($this->configProvider->isAssetEnabled('XRPL', 'RLUSD'));
        $this->assertFalse($this->configProvider->isAssetEnabled('XRPL', 'USDC'));
        $this->assertFalse($this->configProvider->isAssetEnabled('XRPL', 'DOGE'));
    }

    public function testIsAssetEnabledIsCaseInsensitive(): void
    {
        $this->assertTrue($this->configProvider->isAssetEnabled('XRPL', 'xrp'));
        $this->assertTrue($this->configProvider->isAssetEnabled('XRPL', 'rlusd'));
    }

    /**
     * A chain this plugin does not speak must fail loudly instead of being
     * answered with XRPL's wallet address.
     */
    public function testAnotherChainIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->configProvider->getDestinationAccount('STELLAR');
    }
}
