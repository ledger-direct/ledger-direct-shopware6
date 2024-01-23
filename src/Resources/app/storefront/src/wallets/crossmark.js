import sdk from '../node_modules/@crossmarkio/sdk'
import xrpToDrops from '../helper/xrpToDrops'
import DomAccess from 'src/helper/dom-access.helper'

function setupCrossmark() {
    window.sdk = sdk;
    this.crossmarkBlocked = false
    if (sdk.isConnected()) {
        this.crossmarkWalletButton.classList.remove('wallet-disabled')
        this.crossmarkWalletButton.classList.add('wallet-active')
        this.crossmarkWalletButton.addEventListener('click', performPayment.bind(this))
    }
}

function performPayment() {
    const fn = pay.bind(this)
    if (!sdk.getAddress()) {
        this.crossmarkBlocked = true
        sdk.signInAndWait().then(async (response) => {
            this.crossmarkBlocked = false
            fn()
        })
    } else {
        fn()
    }
}

async function pay() {
    console.log('pay')
    const account = sdk.getAddress()
    const paymentPayload = preparePaymentPayload.bind(this)(account)
    console.log(paymentPayload)
    this.crossmarkBlocked = true
    return await sdk.signAndSubmitAndWait(paymentPayload).then((response) => {
        this.crossmarkBlocked = false
        console.log(response)
        this.checkPayment()
    }, (reason) => {
        this.crossmarkBlocked = false
        console.log(reason)
    }).catch(error => {
        console.log(error)
    }).finally(() => {
        console.log('finally');
    })

}

function preparePaymentPayload(account) {
    // XRP Payment
    try {
        this.xrpAmountInput = DomAccess.querySelector(document, '#xrp-amount')

        const xrpPaymentData = {
            amount: parseFloat(this.xrpAmountInput.value).toFixed(6),
            destination: this.destinationAccount.dataset.value,
            destinationTag: parseInt(this.destinationTag.dataset.value)
        }

        return {
            TransactionType: 'Payment',
            Account: account,
            Destination: xrpPaymentData.destination,
            DestinationTag: xrpPaymentData.destinationTag,
            Amount: xrpToDrops(xrpPaymentData.amount), // converted to drops
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
            TransactionType: 'Payment',
            Account: sdk.sign({TransactionType: 'SignIn'}),
            Destination: this.destinationAccount.dataset.value,
            DestinationTag: parseInt(this.destinationTag.dataset.value),
            Amount: {
                currency: currencyInput.value,
                issuer: issuerInput.value,
                value: tokenAmountInput.value
            },
        }
    } catch (error) {
        this.log(error)
    }

    this.log('pay7')
    throw new Error('Could not generate payload for Crossmark')
}

export default setupCrossmark