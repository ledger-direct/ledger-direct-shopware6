<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Port;

use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
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
     * A chain the catalogue knows but the merchant has not switched on, and
     * one the catalogue does not know at all, are both answered the way the
     * port expresses "not available": no account, no asset, testnet.
     */
    public function testAChainThatIsOffOrUnknownHasNoAccountAndNoAsset(): void
    {
        $this->assertSame('', $this->configProvider->getDestinationAccount('STELLAR'), 'never switched on');
        $this->assertFalse($this->configProvider->isAssetEnabled('STELLAR', 'XLM'));
        $this->assertSame('testnet', $this->configProvider->getNetwork('STELLAR'));

        $this->assertSame('', $this->configProvider->getDestinationAccount('DOGECHAIN'));
        $this->assertFalse($this->configProvider->isAssetEnabled('DOGECHAIN', 'DOGE'));
        $this->assertSame('testnet', $this->configProvider->getNetwork('DOGECHAIN'));
    }

    public function testAChainSwitchedOffOffersNothingEvenWithAnAccount(): void
    {
        $configuration = Fixtures::getStaticConfiguration();
        $configuration['LedgerDirect.config.xrplEnabled'] = false;
        $provider = new ShopwareConfigProvider(ConfigurationServiceMock::createInstance($configuration));

        $this->assertSame('', $provider->getDestinationAccount('XRPL'));
        $this->assertFalse($provider->isAssetEnabled('XRPL', 'XRP'), 'not even the native asset');
        $this->assertSame('testnet', $provider->getNetwork('XRPL'), 'the network is still answered: open orders keep syncing');
    }

    public function testASecondChainIsAnsweredFromItsOwnCard(): void
    {
        $configuration = Fixtures::getStaticConfiguration() + [
            'LedgerDirect.config.stellarEnabled' => true,
            'LedgerDirect.config.useStellarTestnet' => true,
            'LedgerDirect.config.stellarTestnetDestinationAccount' => 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA',
            'LedgerDirect.config.stellarIsUsdcEnabled' => false,
        ];
        $provider = new ShopwareConfigProvider(ConfigurationServiceMock::createInstance($configuration));

        $this->assertSame('GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA', $provider->getDestinationAccount('STELLAR'));
        $this->assertTrue($provider->isAssetEnabled('STELLAR', 'XLM'), 'native: on with the chain');
        $this->assertFalse($provider->isAssetEnabled('STELLAR', 'USDC'), 'toggle off');
        $this->assertTrue($provider->isAssetEnabled('STELLAR', 'EURC'), 'toggle never stored: on');
        $this->assertFalse($provider->isAssetEnabled('STELLAR', 'RLUSD'), 'not a Stellar asset');
        $this->assertSame('rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr', $provider->getDestinationAccount('XRPL'), 'the other chain is untouched');
    }
}
