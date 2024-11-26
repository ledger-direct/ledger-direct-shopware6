<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Manual\ClassHelper;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use PHPUnit\Framework\TestCase;

class ConfigurationServiceTest extends TestCase
{
    private ConfigurationService $configurationService;

    protected function setUp(): void
    {
        /*
        $this->systemConfigService = $this->createMock(SystemConfigService::class);

        $this->systemConfigService->method('get')->willReturnMap([
            ['LedgerDirect.config.useTestnet', null, true],
            ['LedgerDirect.config.xrplTestnetAccount', null, 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr'],
            ['LedgerDirect.config.xrplTestnetCustomTokenName', null, 'EUR'],
            ['LedgerDirect.config.xrplTestnetCustomTokenIssuer', null,  'rUFqxm6cfRQTvxgAqJny1dGMprrXQXhTLb']
        ]);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configurationService = new ConfigurationService($this->systemConfigService, $this->logger);
        */

        ClassHelper::getClassesInNamespace('Hardcastle\LedgerDirect\Service');
        $this->configurationService = ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration());
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