import template from './template.html.twig';

const { Component } = Shopware;

/**
 * Sits at the end of each chain's card in the plugin configuration and says
 * what the saved receiving account lacks: a token without a trust line is not
 * offered in the checkout, an account that is not activated cannot take small
 * orders yet, deposit authorization lets only pre-authorized senders pay. The
 * options stay visible and switchable; this only explains. The server reads
 * the ledger (ReceivingAccountController); nothing is decided here.
 *
 * config.xml: <component name="ledger-direct-account-check"><name>…</name><chain>XRPL</chain></component>
 */
Component.register('ledger-direct-account-check', {
    template,

    inject: ['ledgerDirectReceivingAccountService'],

    props: {
        chain: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            isLoading: false,
            /** { chain, nativeAsset, network, account, findings } or null */
            result: null,
            failed: false,
        };
    },

    computed: {
        findings() {
            return this.result ? this.result.findings : [];
        },
    },

    created() {
        this.check();
    },

    methods: {
        check() {
            this.isLoading = true;
            this.failed = false;

            return this.ledgerDirectReceivingAccountService.check(this.chain)
                .then((result) => {
                    this.result = result;
                })
                .catch(() => {
                    this.result = null;
                    this.failed = true;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        variant(finding) {
            if (finding.code === 'not_checked' || finding.code === 'deposit_auth') {
                return 'info';
            }

            return finding.blocksOffer || finding.code === 'account_missing' ? 'attention' : 'info';
        },

        message(finding) {
            const key = finding.code === 'account_missing'
                ? `ledger-direct.accountCheck.finding.account_missing.${this.result.chain}`
                : `ledger-direct.accountCheck.finding.${finding.code}`;

            return this.$t(key, {
                asset: finding.asset ?? '',
                issuer: finding.context?.issuer ?? '',
                reserve: finding.context?.reserve ?? '',
                error: finding.context?.error ?? '',
                native: this.result.nativeAsset,
                account: this.result.account,
                network: this.result.network,
            });
        },
    },
});
