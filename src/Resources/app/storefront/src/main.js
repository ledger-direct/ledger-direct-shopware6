const PluginManager = window.PluginManager;

import Plugin from 'src/plugin-system/plugin.class';
import DomAccess from 'src/helper/dom-access.helper';
import HttpClient from 'src/service/http-client.service';

class XrpPayment  extends Plugin {
    init() {
        window.DomAccess = DomAccess;

        this.client = new HttpClient();

        this.copyDestinationAccountInput = DomAccess.querySelector(document, '#destination-account');
        this.copyDestinationTagInput = DomAccess.querySelector(document, '#destination-tag');
        this.checkPaymentButton = DomAccess.querySelector(document, '#check-payment-button');

        this.registerEvents()

        //process.env.NODE_ENV
    }

    registerEvents() {
        this.copyDestinationAccountInput.nextElementSibling.addEventListener('click', this.copyToClipboard.bind(this, this.copyDestinationAccountInput));
        this.copyDestinationTagInput.nextElementSibling.addEventListener('click', this.copyToClipboard.bind(this, this.copyDestinatinoTagInput));
        //this.checkPaymentButton.addEventListener('click', this.fetchPaymentData.bind(this));
    }

    fetchPaymentData() {
        const orderId = this.checkPaymentButton.dataset.orderId;
        this.client.get('/ledger-direct/check-payment/' + orderId , this.handlePaymentData.bind(this), 'application/json', true);
    }

    handlePaymentData(data) {
        const result = JSON.parse(data);
        if(result.success) {

        }

    }

    copyToClipboard(element, event) {
        navigator.clipboard.writeText(element.value);
    }
}

PluginManager.register(
    'XrpPayment',
    XrpPayment,
    '[data-xrp-payment-page]'
);