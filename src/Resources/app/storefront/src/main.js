/**
 * The Shopware hull around the framework-free payment page: registers a
 * storefront plugin on the page's root element and hands it to payment-ui.
 * Everything the page does lives in ./payment-ui; nothing Shopware-specific
 * is allowed in there.
 */
import { startPaymentPage } from './payment-ui/payment-page';

const PluginManager = window.PluginManager;
const Plugin = window.PluginBaseClass;

class LedgerDirectPaymentPage extends Plugin {
    init() {
        this.page = startPaymentPage(this.el);
    }
}

PluginManager.register('LedgerDirectPaymentPage', LedgerDirectPaymentPage, '[data-ld-page]');
