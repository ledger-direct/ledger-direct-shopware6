import template from './template.html.twig';

const { Component } = Shopware;

/** Every LedgerDirect handler lives in this namespace: one per chain, plus the pre-1.6 per-asset ones. */
const LEDGER_DIRECT_HANDLER_NAMESPACE = 'Hardcastle\\LedgerDirect\\Components\\PaymentHandler\\';

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

            return typeof handler === 'string' && handler.startsWith(LEDGER_DIRECT_HANDLER_NAMESPACE);
        },
    },
});
