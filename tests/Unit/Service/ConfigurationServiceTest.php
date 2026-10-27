<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use PHPUnit\Framework\TestCase;

class ConfigurationServiceTest extends TestCase
{
    private ConfigurationService $configurationService;

    protected function setUp(): void
    {
        $this->configurationService = ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration());
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testIsTestnet(): void
    {
        $this->assertTrue($this->configurationService->isTestnet('XRPL'));
    }

    public function testGetNetwork(): void
    {
        $this->assertSame('testnet', $this->configurationService->getNetwork('XRPL'));
    }

    public function testGetDestinationAccount(): void
    {
        $this->assertSame(
            'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr',
            $this->configurationService->getDestinationAccount('XRPL')
        );
    }

    /**
     * The network toggle is read from the key config.xml actually writes
     * (useXrplTestnet). It used to read a key that was never stored, so the
     * plugin silently stayed on testnet however the merchant set it — with
     * the testnet wallet as the destination.
     */
    public function testMainnetIsSelectedWhenTheTestnetToggleIsOff(): void
    {
        $configuration = Fixtures::getStaticConfiguration();
        $configuration['LedgerDirect.config.useXrplTestnet'] = false;

        $configurationService = ConfigurationServiceMock::createInstance($configuration);

        $this->assertFalse($configurationService->isTestnet('XRPL'));
        $this->assertSame('mainnet', $configurationService->getNetwork('XRPL'));
        $this->assertSame(
            'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            $configurationService->getDestinationAccount('XRPL')
        );
    }

    /**
     * A stored false must not read as "unset" and fall back to the default,
     * or the toggle could never be switched off.
     */
    public function testStoredFalseWins(): void
    {
        $this->assertTrue($this->configurationService->isAssetEnabled('XRPL', 'RLUSD'));
        $this->assertFalse($this->configurationService->isAssetEnabled('XRPL', 'USDC'));
    }

    public function testAssetTogglesDefaultToEnabledWhenUnconfigured(): void
    {
        $configurationService = ConfigurationServiceMock::createInstance([]);

        $this->assertTrue($configurationService->isAssetEnabled('XRPL', 'RLUSD'));
        $this->assertTrue($configurationService->isAssetEnabled('XRPL', 'USDC'));
    }

    /**
     * The keys follow the chain's name — `xrplIsRlusdEnabled`,
     * `stellarIsUsdcEnabled`, `useStellarTestnet` — so a second chain's card
     * is read without this class knowing the chain.
     */
    public function testASecondChainIsReadFromItsOwnKeys(): void
    {
        $configurationService = ConfigurationServiceMock::createInstance([
            'LedgerDirect.config.stellarEnabled' => true,
            'LedgerDirect.config.useStellarTestnet' => false,
            'LedgerDirect.config.stellarMainnetDestinationAccount' => ' GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA ',
            'LedgerDirect.config.stellarIsEurcEnabled' => false,
        ]);

        $this->assertTrue($configurationService->isChainEnabled('STELLAR'));
        $this->assertSame('mainnet', $configurationService->getNetwork('STELLAR'));
        $this->assertSame('GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA', $configurationService->getDestinationAccount('STELLAR'), 'trimmed');
        $this->assertSame('', $configurationService->getAccount('STELLAR', 'testnet'));
        $this->assertFalse($configurationService->isAssetEnabled('STELLAR', 'EURC'));
        $this->assertTrue($configurationService->isAssetEnabled('STELLAR', 'USDC'), 'on by default');
    }

    /**
     * The chain switch did not exist before 1.6.0. An installation with a
     * receiving account configured keeps working as it did; one without has
     * nothing to switch on; a stored value wins either way. A chain the
     * merchant never touched is off.
     */
    public function testTheChainSwitchDefaultsToOnWhenAnAccountIsConfigured(): void
    {
        $this->assertTrue($this->configurationService->isChainEnabled('XRPL'), 'testnet account set, switch never stored');

        $withoutAccount = ConfigurationServiceMock::createInstance(['LedgerDirect.config.useXrplTestnet' => true]);
        $this->assertFalse($withoutAccount->isChainEnabled('XRPL'));

        $switchedOff = Fixtures::getStaticConfiguration();
        $switchedOff['LedgerDirect.config.xrplEnabled'] = false;
        $this->assertFalse(ConfigurationServiceMock::createInstance($switchedOff)->isChainEnabled('XRPL'), 'a stored false wins over the account');

        $this->assertFalse($this->configurationService->isChainEnabled('STELLAR'));
    }

    public function testGetQuoteExpirySeconds(): void
    {
        $this->assertSame(600, $this->configurationService->getQuoteExpirySeconds());
    }

    public function testGetQuoteExpirySecondsFallsBackToTheDefault(): void
    {
        $configurationService = ConfigurationServiceMock::createInstance([]);

        $this->assertSame(
            ConfigurationService::DEFAULT_QUOTE_EXPIRY_SECONDS,
            $configurationService->getQuoteExpirySeconds()
        );
    }
}
