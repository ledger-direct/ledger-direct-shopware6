import template from './template.html.twig';

const { Component } = Shopware;

const LEDGER_DIRECT_HANDLERS = [
    'Hardcastle\\LedgerDirect\\Components\\PaymentHandler\\XrpPaymentHandler',
    'Hardcastle\\LedgerDirect\\Components\\PaymentHandler\\RlusdPaymentHandler',
    'Hardcastle\\LedgerDirect\\Components\\PaymentHandler\\UsdcPaymentHandler',
];

/**
 * Hangs the LedgerDirect card under Shopware's transaction card on the
 * Details tab, for the transaction Shopware itself picked (its `transaction`
 * computed: the first one that is not cancelled or failed), when that
 * transaction's payment method is one of ours.
 */
Component.override('sw-order-detail-details', {
    template,

    computed: {
        isLedgerDirectTransaction() {
            const transaction = this.transaction;
            const handler = transaction && transaction.paymentMethod ? transaction.paymentMethod.handlerIdentifier : null;

            return LEDGER_DIRECT_HANDLERS.includes(handler);
        },
    },
});
