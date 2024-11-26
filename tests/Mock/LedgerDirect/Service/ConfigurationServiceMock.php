<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use Mockery;

class ConfigurationServiceMock
{
    public static function createInstance(): ConfigurationService
    {
        $systemConfigServiceMock = Mockery::mock(SystemConfigService::class);

        $systemConfigServiceMock->shouldReceive('get')
            ->with('LedgerDirect.config.useTestnet')
            ->andReturn(true);
        $systemConfigServiceMock->shouldReceive('get')
            ->with('LedgerDirect.config.xrplTestnetAccount')
            ->andReturn('rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr');
        $systemConfigServiceMock->shouldReceive('get')
            ->with('LedgerDirect.config.xrplTestnetCustomTokenName')
            ->andReturn('EUR');
        $systemConfigServiceMock->shouldReceive('get')
            ->with('LedgerDirect.config.xrplTestnetCustomTokenIssuer')
            ->andReturn('rUFqxm6cfRQTvxgAqJny1dGMprrXQXhTLb');

        $loggerMock = Mockery::mock(LoggerInterface::class);

        return new ConfigurationService($systemConfigServiceMock, $loggerMock);
    }

    public static function createMock(): ConfigurationService
    {
        return Mockery::mock(ConfigurationService::class);
    }
}