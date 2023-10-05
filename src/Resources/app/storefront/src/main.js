const PluginManager = window.PluginManager

import Plugin from 'src/plugin-system/plugin.class'
import DomAccess from 'src/helper/dom-access.helper'
import HttpClient from 'src/service/http-client.service'

import setupGemWallet from "./wallets/gemWallet"
import setupCrossmark from "./wallets/crossmark"

class XrpPayment extends Plugin {
    init() {
        this.debug = true;

        this.client = new HttpClient();

        this.destinationAccountInput = DomAccess.querySelector(document, '#destination-account')
        this.destinationTagInput = DomAccess.querySelector(document, '#destination-tag')
        this.checkPaymentButton = DomAccess.querySelector(document, '#check-payment-button')
        this.spinner = DomAccess.querySelector(this.checkPaymentButton, 'span')

        this.gemWalletButton = DomAccess.querySelector(document, '#gem-wallet-button')
        this.crossmarkWalletButton = DomAccess.querySelector(document, '#crossmark-wallet-button')
        this.xummWalletButton = DomAccess.querySelector(document, '#xumm-wallet-button')

        this.registerEvents()

        setTimeout(this.setupWallets.bind(this), 1000)
    }

    registerEvents() {
        this.destinationAccountInput.nextElementSibling.addEventListener('click', this.copyToClipboard.bind(this, this.destinationAccountInput))
        this.destinationTagInput.nextElementSibling.addEventListener('click', this.copyToClipboard.bind(this, this.destinationTagInput))
        this.checkPaymentButton.addEventListener('click', this.checkPayment.bind(this))
    }

    setupWallets() {
        setupGemWallet.bind(this)()
        setupCrossmark.bind(this)()
    }

    checkPayment() {
        const orderId = this.checkPaymentButton.dataset.orderId
        this.spinner.style.display = 'inline-block'
        this.checkPaymentButton.disabled = true
        this.client.get('/ledger-direct/payment/check/' + orderId , this.handlePaymentData.bind(this), 'application/json', true)
    }

    handlePaymentData(data) {
        const result = JSON.parse(data);
        if(result.success) {
            location.reload()
        } else {
            this.spinner.style.display = 'none'
            this.checkPaymentButton.disabled = false
        }
    }

    copyToClipboard(element, event) {
        console.log('cop-to-clipboard')
        navigator.clipboard.writeText(element.value)
    }

    log(value) {
        if (this.debug) {
            console.log(value)
        }
    }
}

PluginManager.register(
    'XrpPayment',
    XrpPayment,
    '[data-xrp-payment-page]'
);