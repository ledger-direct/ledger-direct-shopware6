<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Service\PreviewChainActivator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;

/**
 * The switch of a preview chain's card is what creates its payment methods.
 */
class PreviewChainActivatorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testSwitchingThePreviewChainOnInstallsItsMethods(): void
    {
        /** @var PaymentMethodInstaller&Mockery\MockInterface $installer */
        $installer = Mockery::mock(PaymentMethodInstaller::class);
        $installer->shouldReceive('installChain')->once()->with(ChainCatalog::STELLAR, Mockery::type(Context::class));

        (new PreviewChainActivator($installer, new NullLogger()))
            ->onChanged(new SystemConfigChangedEvent('LedgerDirect.config.stellarEnabled', true, null));
    }

    public function testNothingElseDoes(): void
    {
        /** @var PaymentMethodInstaller&Mockery\MockInterface $installer */
        $installer = Mockery::mock(PaymentMethodInstaller::class);
        $installer->shouldReceive('installChain')->never();
        $activator = new PreviewChainActivator($installer, new NullLogger());

        // switched off, a released chain, another key, another domain
        $activator->onChanged(new SystemConfigChangedEvent('LedgerDirect.config.stellarEnabled', false, null));
        $activator->onChanged(new SystemConfigChangedEvent('LedgerDirect.config.xrplEnabled', true, null));
        $activator->onChanged(new SystemConfigChangedEvent('LedgerDirect.config.stellarIsUsdcEnabled', true, null));
        $activator->onChanged(new SystemConfigChangedEvent('core.basicInformation.shopName', 'Stellar Shop', null));
    }

    public function testTheKeyRuleIsNamedAfterTheCatalogue(): void
    {
        $this->assertSame('STELLAR', PreviewChainActivator::previewChainSwitchedOn('LedgerDirect.config.stellarEnabled', '1'));
        $this->assertNull(PreviewChainActivator::previewChainSwitchedOn('LedgerDirect.config.stellarEnabled', 0));
        $this->assertNull(PreviewChainActivator::previewChainSwitchedOn('LedgerDirect.config.xrplEnabled', true), 'XRPL is released, not a preview');
    }
}
