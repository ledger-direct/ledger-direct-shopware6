import template from './template.html.twig';
import './style.scss';

const { Component } = Shopware;

/**
 * "Received payments" on the order detail's Details tab: for every order
 * transaction with a LedgerDirect payment record, the ledger payments stored
 * for its account and tag. A cancelled or failed transaction is listed like
 * the current one, because a customer can still pay an attempt the shop has
 * given up on. Read-only; the server decides what is listed and whether a
 * payment is in the requested asset (ReceivedPaymentsController).
 */
Component.register('ledger-direct-received-payments', {
    template,

    inject: ['ledgerDirectReceivedPaymentsService'],

    props: {
        orderId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            isLoading: true,
            /** [{ orderTransactionId, state, stateName, unreadable, chain, network, asset, amountRequested, issuer, payments[] }] */
            transactions: [],
            failed: false,
        };
    },

    computed: {
        /** Nothing to show: the order has no LedgerDirect transaction, or no longer exists. */
        hidden() {
            return !this.isLoading && !this.failed && this.transactions.length === 0;
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },
    },

    watch: {
        orderId() {
            this.load();
        },
    },

    created() {
        // Counts the requests. Not in data(): nothing renders from it.
        this.latestLoad = 0;
        this.load();
    },

    methods: {
        async load() {
            // The order can change while an answer is on its way, and the older request can
            // answer last. Only the newest one writes: an answer for the order shown before
            // must neither replace the rows of this one nor end its loading.
            this.latestLoad += 1;
            const request = this.latestLoad;

            this.isLoading = true;
            this.failed = false;

            try {
                const answer = await this.ledgerDirectReceivedPaymentsService.payments(this.orderId);

                if (request === this.latestLoad) {
                    this.transactions = answer.transactions;
                }
            } catch (error) {
                if (request === this.latestLoad) {
                    this.transactions = [];
                    // An order that is gone has no payments to show; anything else is said.
                    this.failed = !(error && error.response && error.response.status === 404);
                }
            } finally {
                if (request === this.latestLoad) {
                    this.isLoading = false;
                }
            }
        },
    },
});
