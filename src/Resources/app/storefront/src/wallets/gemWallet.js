import { isInstalled, sendPayment } from "../node_modules/@gemwallet/api";
import xrpToDrops from '../helper/xrpToDrops';

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
    console.log(this.xrpPaymentData)
    return {
        amount: xrpToDrops(this.xrpPaymentData.amount), // converted to drops
        destination: this.xrpPaymentData.destination,
        destinationTag: this.xrpPaymentData.destinationTag
    }
}

export default setupGemWallet;