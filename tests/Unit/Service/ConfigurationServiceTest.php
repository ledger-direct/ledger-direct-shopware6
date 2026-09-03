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

    public function testIsTest(): void
    {
        $this->assertTrue($this->configurationService->isTest());
    }

    public function testGetNetwork(): void
    {
        $this->assertSame('testnet', $this->configurationService->getNetwork());
    }

    public function testGetDestinationAccount(): void
    {
        $this->assertSame(
            'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr',
            $this->configurationService->getDestinationAccount()
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

        $this->assertFalse($configurationService->isTest());
        $this->assertSame('mainnet', $configurationService->getNetwork());
        $this->assertSame(
            'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            $configurationService->getDestinationAccount()
        );
    }

    /**
     * A stored false must not read as "unset" and fall back to the default,
     * or the toggle could never be switched off.
     */
    public function testStoredFalseWins(): void
    {
        $this->assertTrue($this->configurationService->isRlusdEnabled());
        $this->assertFalse($this->configurationService->isUsdcEnabled());
    }

    public function testAssetTogglesDefaultToEnabledWhenUnconfigured(): void
    {
        $configurationService = ConfigurationServiceMock::createInstance([]);

        $this->assertTrue($configurationService->isRlusdEnabled());
        $this->assertTrue($configurationService->isUsdcEnabled());
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
