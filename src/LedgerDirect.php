<?php declare(strict_types=1);

namespace LedgerDirect;

use LedgerDirect\Installer\PaymentMethodInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;


class LedgerDirect extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);

        $pmi = new PaymentMethodInstaller($paymentMethodRepository, $pluginIdProvider);
        $pmi->install($installContext);
    }

    public function update(UpdateContext $updateContext): void
    {
        /** @var PaymentMethodRepositoryDecorator $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        /** @var PaymentMethodRepositoryDecorator $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        /** @var PaymentMethodRepositoryDecorator $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');
    }
}