import template from './template.html.twig';

const { Component } = Shopware;

/** Every LedgerDirect handler lives in this namespace: one per chain, plus the pre-1.6 per-asset ones. */
const LEDGER_DIRECT_HANDLER_NAMESPACE = 'Hardcastle\\LedgerDirect\\Components\\PaymentHandler\\';

/**
 * Hangs the LedgerDirect card under Shopware's transaction card on the
 * Details tab, for the transaction Shopware itself picked (its `transaction`
 * computed: the first one that is not cancelled or failed), when that
 * transaction's payment method is one of ours.
 *
 * Under it the received payments, for every order with a LedgerDirect
 * payment record on any of its transactions: also when the one Shopware
 * picked is cancelled or belongs to another payment method.
 */
Component.override('sw-order-detail-details', {
    template,

    computed: {
        isLedgerDirectTransaction() {
            const transaction = this.transaction;
            const handler = transaction && transaction.paymentMethod ? transaction.paymentMethod.handlerIdentifier : null;

            return typeof handler === 'string' && handler.startsWith(LEDGER_DIRECT_HANDLER_NAMESPACE);
        },

        /**
         * The order's transactions with a payment record (the custom field the server lists
         * by, too), each with its state: empty for an order that has none, and a new value
         * when a transaction is added or changes its state, so the list is read again.
         */
        ledgerDirectReceivedPaymentsKey() {
            const transactions = this.order && this.order.transactions ? this.order.transactions : [];

            return transactions
                .filter((transaction) => transaction.customFields && transaction.customFields.ledger_direct)
                .map((transaction) => `${transaction.id}:${transaction.stateId}`)
                .join(',');
        },
    },

    methods: {
        /**
         * The received payments were refreshed, and a transaction may be settled now: by that
         * refresh, or before it by the scheduled task or the customer's payment page. The
         * payment card above reads the transaction by its id, which has not changed, so nothing
         * else would make it load again, and it would go on saying "waiting" above a list that
         * shows the payment.
         *
         * Shopware's own state card is left as it is. The page shows a draft version of the
         * order, taken when it was opened; reading the new state into it means saving and
         * merging that draft (what Shopware does after a state change made on this page), and
         * a button that only fetches must not save the edits someone is in the middle of.
         */
        onLedgerDirectPaymentsRefreshed() {
            const paymentCard = this.$refs.ledgerDirectPaymentCard;

            if (paymentCard) {
                paymentCard.load();
            }
        },
    },
});
