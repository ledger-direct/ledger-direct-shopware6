<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect;

use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class LedgerDirect extends Plugin
{
    /**
     * Lets Shopware resolve this plugin's composer requirements — notably
     * hardcastle/ledger-direct-core, which holds the pricing, XRPL and
     * destination-tag logic — when the plugin is installed.
     *
     * Shopware only registers a plugin's own PSR-4 namespaces; it never loads
     * a vendor directory shipped inside a plugin. Without this, the core would
     * have to be installed into the shop by hand before the plugin could run.
     */
    public function executeComposerCommands(): bool
    {
        return true;
    }

    public function install(InstallContext $installContext): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);

        $pmi = new PaymentMethodInstaller($paymentMethodRepository, $pluginIdProvider);
        $pmi->install($installContext);
    }

    /**
     * Writes the payment methods again on every update: a new chain or asset
     * in the core's catalogue gets its method, an existing one its current
     * handler identifier. Active flags stay as the merchant set them.
     */
    public function update(UpdateContext $updateContext): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);

        (new PaymentMethodInstaller($paymentMethodRepository, $pluginIdProvider))->update($updateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        /** @var EntityRepository  $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        /** @var EntityRepository  $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');
    }
}