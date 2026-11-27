<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect;

use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
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
        $this->paymentMethodInstaller()->install($installContext);
    }

    /**
     * Writes the payment methods again on every update: a new chain or asset
     * in the core's catalogue gets its method, an existing one its current
     * handler identifier. Active flags stay as the merchant set them.
     */
    public function update(UpdateContext $updateContext): void
    {
        $this->paymentMethodInstaller()->update($updateContext);
    }

    /**
     * Switches the plugin's payment methods on. Whether one is offered at
     * checkout is decided elsewhere: its chain has to be on and have a
     * receiving address, and the method has to be assigned to the sales
     * channel.
     */
    public function activate(ActivateContext $activateContext): void
    {
        $this->paymentMethodInstaller()->activate($activateContext);
    }

    /**
     * Switches the plugin's payment methods off. With the plugin inactive
     * nothing of it runs, the filter of the checkout included: a method left
     * on would still be offered there, with no handler behind it.
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->paymentMethodInstaller()->deactivate($deactivateContext);
    }

    /**
     * Switches the payment methods off; they stay, for the orders that carry
     * them. Shopware deactivates an active plugin before it uninstalls it,
     * so this is for a plugin that was installed and never activated, or
     * whose methods were switched on by hand after it was deactivated.
     *
     * No table is dropped, whatever the merchant chose for the plugin's
     * data: the destination-tag counter cannot be reconstructed, and reusing
     * tags would match new orders against old payments.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->paymentMethodInstaller()->uninstall($uninstallContext);
    }

    private function paymentMethodInstaller(): PaymentMethodInstaller
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);

        return new PaymentMethodInstaller($paymentMethodRepository, $pluginIdProvider);
    }
}
