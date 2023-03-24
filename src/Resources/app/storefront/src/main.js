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
        this.spinner = DomAccess.querySelector(this.checkPaymentButton, 'span');

        this.registerEvents()

        //process.env.NODE_ENV

        window.ldPlugin = this;
    }

    registerEvents() {
        this.copyDestinationAccountInput.nextElementSibling.addEventListener('click', this.copyToClipboard.bind(this, this.copyDestinationAccountInput));
        this.copyDestinationTagInput.nextElementSibling.addEventListener('click', this.copyToClipboard.bind(this, this.copyDestinationTagInput));
        this.checkPaymentButton.addEventListener('click', this.fetchPaymentData.bind(this));
    }

    fetchPaymentData() {
        const orderId = this.checkPaymentButton.dataset.orderId;
        this.spinner.style.display = 'inline-block';
        this.checkPaymentButton.disabled = true;
        this.client.get('/ledger-direct/payment/check/' + orderId , this.handlePaymentData.bind(this), 'application/json', true);
    }

    handlePaymentData(data) {
        const result = JSON.parse(data);
        if(result.success) {
            location.reload()
        } else {
            this.spinner.style.display = 'none';
            this.checkPaymentButton.disabled = false;
        }
    }

    copyToClipboard(element, event) {
        console.log('cop-to-clipboard');
        navigator.clipboard.writeText(element.value);
    }
}

PluginManager.register(
    'XrpPayment',
    XrpPayment,
    '[data-xrp-payment-page]'
);