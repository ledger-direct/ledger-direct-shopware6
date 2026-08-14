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
}
