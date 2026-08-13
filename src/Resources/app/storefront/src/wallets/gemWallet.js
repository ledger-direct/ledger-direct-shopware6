import { isInstalled, sendPayment } from "../node_modules/@gemwallet/api"
import xrpToDrops from '../helper/xrpToDrops'
import DomAccess from 'src/helper/dom-access.helper'

function setupGemWallet() {
    isInstalled().then((response) => {
        if (response.result.isInstalled) {
            this.gemWalletButton.classList.remove('wallet-disabled');
            this.gemWalletButton.classList.add('wallet-active');
            this.gemWalletButton.addEventListener('click', performPayment.bind(this))
        }
    });
}

function performPayment() {
    const paymentPayload = preparePaymentPayload.bind(this)();
    console.log('GemWallet payload:', paymentPayload);

    sendPayment(paymentPayload).then((response) => {
        console.log('GemWallet response:', response);
        const hash = response && response.result && response.result.hash;
        if (hash) {
            this.log('Payment hash: ' + hash);
            this.checkPayment();
        } else {
            console.warn('No hash returned from GemWallet', response);
        }
    }, (reason) => {
        console.error('GemWallet payment failed (reason)', reason);
        this.log(reason);
    }).catch(error => {
        console.error('GemWallet payment failed (error)', error);
    })
}

function preparePaymentPayload() {
    // XRP Payment
    try {
        this.xrpAmount = DomAccess.querySelector(document, '#xrp-amount')

        const xrpPaymentData = {
            amount: parseFloat(this.xrpAmount.value).toFixed(6),
            destination: this.destinationAccount.dataset.value,
            destinationTag: parseInt(this.destinationTag.dataset.value)
        }

        return {
            amount: typeof xrpPaymentData.amount === 'string' ? xrpToDrops(xrpPaymentData.amount) : xrpPaymentData.amount, // converted to drops
            destination: xrpPaymentData.destination,
            destinationTag: xrpPaymentData.destinationTag
        }
    } catch (error) {
        this.log(error)
    }

    // Token Payment
    try {
        const tokenAmountInput = DomAccess.querySelector(document, '#token-amount')
        const issuerInput = DomAccess.querySelector(document, '#issuer')
        const currencyInput = DomAccess.querySelector(document, '#currency')

        return {
            amount: {
                currency: currencyInput.value,
                issuer: issuerInput.value,
                value: tokenAmountInput.value
            },
            destination: this.destinationAccount.dataset.value,
            destinationTag: parseInt(this.destinationTag.dataset.value)
        }
    } catch (error) {
        console.log(error)
    }

    throw new Error('Could not generate payload for GemWallet')
}

export default setupGemWallet