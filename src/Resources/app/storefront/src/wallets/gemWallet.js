import { isInstalled, sendPayment } from "../node_modules/@gemwallet/api";
import xrpToDrops from '../helper/xrpToDrops';
import DomAccess from 'src/helper/dom-access.helper';
function setupGemWallet() {
    isInstalled().then((response) => {
        if (response.result.isInstalled) {
            this.gemWalletButton.classList.remove('wallet-disabled');
            this.gemWalletButton.classList.add('wallet-active');
            this.gemWalletButton.addEventListener('click', requestPayment.bind(this))
        }
    });
}

function requestPayment() {
    const paymentPayload = preparePaymentPayload.bind(this)();
    console.log(paymentPayload)
    sendPayment(paymentPayload).then((response) => {
        console.log(response);
        console.log(response.result?.hash);

    }, (reason) => {
        console.log('fail')
        console.log(reason)
    })
}

function preparePaymentPayload() {
    // XRP Payment
    try {
        this.xrpAmountInput = DomAccess.querySelector(document, '#xrp-amount');

        const xrpPaymentData = {
            amount: parseFloat(this.xrpAmountInput.value),
            destination: this.destinationAccountInput.value,
            destinationTag: parseInt(this.destinationTagInput.value)
        }

        console.log(this.xrpPaymentData)

        return {
            amount: xrpToDrops(this.xrpPaymentData.amount), // converted to drops
            destination: this.xrpPaymentData.destination,
            destinationTag: this.xrpPaymentData.destinationTag
        }
    } catch (error) {
        console.log(error)
    }

    // Token Payment
    try {
        const tokenAmountInput = DomAccess.querySelector(document, '#token-amount');
        const issuerInput = DomAccess.querySelector(document, '#issuer');
        const currencyInput = DomAccess.querySelector(document, '#currency');

        const tokenPaymentData = {
            amount: {
                currency: currencyInput.value,
                issuer: issuerInput.value,
                value: tokenAmountInput.value
            },
            destination: this.destinationAccountInput.value,
            destinationTag: parseInt(this.destinationTagInput.value)
        }

        console.log(tokenPaymentData)

        return tokenPaymentData
    } catch (error) {
        console.log(error);
    }


    throw new Error('Could not generate payload for GemWallet')
}

export default setupGemWallet;