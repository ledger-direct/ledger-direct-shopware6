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

        this.destinationAccount = DomAccess.querySelector(document, '#destination-account')
        this.destinationTag = DomAccess.querySelector(document, '#destination-tag')
        this.checkPaymentButton = DomAccess.querySelector(document, '#check-payment-button')
        this.spinner = DomAccess.querySelector(this.checkPaymentButton, 'span')

        //this.gemWalletButton = DomAccess.querySelector(document, '#gem-wallet-button')
        //this.crossmarkWalletButton = DomAccess.querySelector(document, '#crossmark-wallet-button')
        //this.xummWalletButton = DomAccess.querySelector(document, '#xumm-wallet-button')

        this.registerEvents()

        setTimeout(this.setupWallets.bind(this), 1000)
    }

    registerEvents() {
        const daIcon = this.destinationAccount.nextElementSibling.firstElementChild
        const dtIcon = this.destinationTag.nextElementSibling.firstElementChild
        daIcon.addEventListener('click', this.copyToClipboard.bind(this, this.destinationAccount, daIcon))
        dtIcon.addEventListener('click', this.copyToClipboard.bind(this, this.destinationTag, dtIcon))
        this.checkPaymentButton.addEventListener('click', this.checkPayment.bind(this))
    }

    setupWallets() {
        //setupGemWallet.bind(this)()
        //setupCrossmark.bind(this)()
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

    copyToClipboard(element, icon, event) {
        if (typeof navigator.clipboard === 'undefined') {
            console.log('Clipboard API not supported - is this a secure context?');

            return;
        }

        const message = 'copied!';
        console.log("icon: " + icon.getAttribute('width'));
        navigator.clipboard.writeText(element.getAttribute("data-value")).then(() => {
            this.showCopyFeedback(message, icon);
        }).catch(err => {
            console.error('Failed to copy: ', err);
            this.showCopyFeedback('Failed to copy to clipboard', icon, true);
        });
    }

    showCopyFeedback(message, icon, isError = false) {
        const oldToast = document.querySelector('.copy-toast')
        if (oldToast) {
            oldToast.remove()
        }

        const toast = document.createElement('div')
        toast.classList.add('copy-toast')
        toast.textContent = message
        toast.style.backgroundColor = isError ? '#f44336' : '#1daae6'

        icon.parentElement.append(toast);

        setTimeout(() => {
            toast.classList.add('fade-out')
            setTimeout(() => toast.remove(), 300)
        }, 3000)
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
)