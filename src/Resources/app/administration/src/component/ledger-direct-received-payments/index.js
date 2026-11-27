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
 *
 * The list is what the shop has stored. "Refresh" has the server ask the
 * ledger first: once a transaction is no longer open, only the scheduled task
 * syncs its account, and a late or second payment would show minutes later.
 */
Component.register('ledger-direct-received-payments', {
    template,

    inject: ['ledgerDirectReceivedPaymentsService'],

    /** refreshed: the list shows what a refresh answered; whatever else on the page shows these transactions is older now. */
    emits: ['refreshed'],

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
            /** The last refresh that asked a node got no answer from it: the rows may not be all there are. */
            ledgerUnreachable: false,
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
        /** What the shop has stored for the order. */
        load() {
            // What an earlier refresh found out about the ledger was said about another list.
            this.ledgerUnreachable = false;

            return this.show(() => this.ledgerDirectReceivedPaymentsService.payments(this.orderId));
        },

        /** The button: the server asks the ledger, then answers the list. */
        async refresh() {
            const answer = await this.show(() => this.ledgerDirectReceivedPaymentsService.refresh(this.orderId));

            if (answer === null) {
                return;
            }

            // "throttled" changes nothing: no node was asked, so it does not take back what the
            // click before found out. The server marks the throttle before it asks, so the click
            // right after a failed one is throttled.
            if (answer.sync === 'failed') {
                this.ledgerUnreachable = true;
            } else if (answer.sync === 'synced') {
                this.ledgerUnreachable = false;
            }

            // Every time, not only when this refresh settled something: a transaction can have
            // been settled since the page was opened, by the scheduled task or the customer's
            // payment page, and then the list says "paid" from now on while the payment card
            // above still shows what it loaded.
            this.$emit('refreshed');
        },

        /**
         * Asks and shows the rows. Answers what was shown, or null when nothing was: the
         * request failed, or it was not the newest any more.
         */
        async show(ask) {
            // The order can change while an answer is on its way, and the older request can
            // answer last. Only the newest one writes: an answer for the order shown before
            // must neither replace the rows of this one nor end its loading. One count for
            // loading and refreshing: a refresh for the order before is such an answer too.
            this.latestLoad += 1;
            const request = this.latestLoad;

            this.isLoading = true;
            this.failed = false;

            try {
                const answer = await ask();

                if (request === this.latestLoad) {
                    this.transactions = answer.transactions;

                    return answer;
                }
            } catch (error) {
                if (request === this.latestLoad) {
                    this.transactions = [];
                    this.ledgerUnreachable = false;
                    // An order that is gone has no payments to show; anything else is said.
                    this.failed = !(error && error.response && error.response.status === 404);
                }
            } finally {
                if (request === this.latestLoad) {
                    this.isLoading = false;
                }
            }

            return null;
        },
    },
});
