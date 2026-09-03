<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use Mockery;

class ConfigurationServiceMock
{
    public static function createInstance(array $configValues): ConfigurationService
    {
        $systemConfigServiceMock = Mockery::mock(SystemConfigService::class);

        foreach ($configValues as $key => $value) {
            $systemConfigServiceMock->shouldReceive('get')
                ->with($key)
                ->andReturn($value);
        }

        /*
         * Catch-all for keys the fixture does not define — declared last, so
         * the specific expectations above still win. Without it, reading an
         * unconfigured setting would fail the test instead of exercising the
         * service's own default handling.
         */
        $systemConfigServiceMock->shouldReceive('get')->andReturn(null);

        $loggerMock = Mockery::mock(LoggerInterface::class);

        return new ConfigurationService($systemConfigServiceMock, $loggerMock);
    }

    public static function createMock(): ConfigurationService
    {
        return Mockery::mock(ConfigurationService::class);
    }
}