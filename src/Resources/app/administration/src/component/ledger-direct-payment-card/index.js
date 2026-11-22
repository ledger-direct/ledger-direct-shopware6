import template from './template.html.twig';
import './style.scss';

const { Component } = Shopware;

/**
 * The LedgerDirect card on the order detail's Details tab: the payment's
 * state in the core's five words, what was quoted, what arrived, what is
 * still due, the transaction with its explorer link. Read-only, and no
 * arithmetic here - every value comes formatted from the server.
 */
Component.register('ledger-direct-payment-card', {
    template,

    inject: ['ledgerDirectPaymentInfoService'],

    props: {
        orderTransactionId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            isLoading: true,
            /** { status, display } from the server, or null */
            info: null,
            /** 'unreadable' for a 422; null otherwise. A 404 hides the card. */
            problem: null,
            hidden: false,
        };
    },

    computed: {
        status() {
            return this.info ? this.info.status : null;
        },

        display() {
            return this.info ? this.info.display : null;
        },

        stateLabel() {
            if (!this.status) {
                return '';
            }

            return this.$tc(`ledger-direct.paymentCard.state.${this.status.state}`);
        },

        stateVariant() {
            if (!this.status) {
                return 'neutral';
            }

            return {
                settled: 'success',
                partial: 'warning',
                wrong_asset: 'danger',
                expired: 'neutral',
                waiting: 'info',
            }[this.status.state] || 'neutral';
        },

        receivedText() {
            if (!this.display || this.display.amountPaid === null) {
                return '';
            }

            if (this.display.wrongAsset && this.display.paidAsset) {
                const issuer = this.display.paidAsset.issuer;

                return issuer
                    ? this.$tc('ledger-direct.paymentCard.receivedWrongToken', 0, {
                        amount: this.display.amountPaid,
                        currency: this.display.paidAsset.currency,
                        issuer,
                    })
                    : this.$tc('ledger-direct.paymentCard.receivedWrongNative', 0, {
                        amount: this.display.amountPaid,
                        currency: this.display.paidAsset.currency,
                    });
            }

            return `${this.display.amountPaid} ${this.display.asset}`;
        },

        // The template formats the quote's validity with it. Without it the row threw while
        // nothing had arrived, and the card stopped at the state label.
        dateFilter() {
            return Shopware.Filter.getByName('date');
        },
    },

    watch: {
        orderTransactionId() {
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
            // Shopware can pick another transaction while an answer is on its way, and the
            // older request can answer last. Only the newest one writes: an answer for the
            // transaction shown before must not put its payment under this one.
            this.latestLoad += 1;
            const request = this.latestLoad;

            this.isLoading = true;
            this.problem = null;
            this.hidden = false;

            try {
                const info = await this.ledgerDirectPaymentInfoService.info(this.orderTransactionId);

                if (request === this.latestLoad) {
                    this.info = info;
                }
            } catch (error) {
                if (request === this.latestLoad) {
                    const code = error && error.response ? error.response.status : null;
                    this.info = null;

                    if (code === 404) {
                        // Not ours, or never quoted: nothing to show.
                        this.hidden = true;
                    } else if (code === 422) {
                        this.problem = 'unreadable';
                    } else {
                        this.problem = 'unavailable';
                    }
                }
            } finally {
                if (request === this.latestLoad) {
                    this.isLoading = false;
                }
            }
        },
    },
});
