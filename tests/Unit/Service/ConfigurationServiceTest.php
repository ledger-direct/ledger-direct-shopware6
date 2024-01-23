<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationServiceTest extends TestCase
{
    private ConfigurationService $configurationService;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->systemConfigService = $this->createMock(SystemConfigService::class);

        $this->systemConfigService->method('get')->willReturnMap([
            ['LedgerDirect.config.useTestnet', null, true],
            ['LedgerDirect.config.xrplTestnetAccount', null, 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr'],
            ['LedgerDirect.config.xrplTestnetCustomTokenName', null, 'EUR'],
            ['LedgerDirect.config.xrplTestnetCustomTokenIssuer', null,  'rUFqxm6cfRQTvxgAqJny1dGMprrXQXhTLb']
        ]);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configurationService = new ConfigurationService($this->systemConfigService, $this->logger);
    }

    public function testIsTest(): void
    {
        $result = $this->configurationService->isTest();
        $this->assertTrue($result);
    }

    public function testGetDestinationAccount(): void
    {
        $result = $this->configurationService->getDestinationAccount();
        $this->assertEquals('rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr', $result);
    }

    public function testGetTokenName(): void
    {
        $result = $this->configurationService->getTokenName();
        $this->assertEquals('EUR', $result);
    }

    public function testGetIssuer(): void
    {
        $result = $this->configurationService->getIssuer();
        $this->assertEquals('rUFqxm6cfRQTvxgAqJny1dGMprrXQXhTLb', $result);
    }
}