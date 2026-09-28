/**
 * The Shopware hull around the shared payment page: registers a storefront
 * plugin on the page's root element and hands it to @ledger-direct/payment-ui.
 * Everything the page does lives in that package; nothing here but the
 * registration. Webpack code-splits the wallet library out of the package's
 * dynamic import, so it loads only when a customer opens the wallet list.
 */
import { startPaymentPage } from '@ledger-direct/payment-ui';

const PluginManager = window.PluginManager;
const Plugin = window.PluginBaseClass;

class LedgerDirectPaymentPage extends Plugin {
    init() {
        this.page = startPaymentPage(this.el);
    }
}

PluginManager.register('LedgerDirectPaymentPage', LedgerDirectPaymentPage, '[data-ld-page]');
