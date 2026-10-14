/**
 * The administration side of LedgerDirect: one card on the order detail that
 * shows what the payment page and the harness already know about an order's
 * XRPL payment. The server computes everything (PaymentInfoController); the
 * component only displays.
 */
import './service/ledger-direct-payment-info.service';
import './component/ledger-direct-payment-card';
import './view/sw-order-detail-details';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
