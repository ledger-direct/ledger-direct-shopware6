const PluginManager = window.PluginManager

const Plugin = window.PluginBaseClass
import DomAccess from 'src/helper/dom-access.helper'
import HttpClient from 'src/service/http-client.service'
import kjua from 'kjua'

// import setupGemWallet from "./wallets/gemWallet"
// import setupCrossmark from "./wallets/crossmark"

/**
 * Payment page behaviour: count the quote down, and ask the server which
 * state the payment is in.
 *
 * Both are conveniences. The page is fully usable without JavaScript — the
 * amount, destination and tag are server-rendered, every state block exists
 * in the markup, the check button is a plain request, and the scheduled task
 * settles the order regardless of whether anyone is watching this page.
 *
 * The poll answers with the core's payment-status payload (state, amounts,
 * seconds left) plus a `redirect` once the order no longer waits. This script
 * knows no sentence a customer reads: it switches the server-rendered blocks
 * and fills in two numbers.
 */
const POLL_INTERVAL_MS = 8000

class XrpPayment extends Plugin {
    init() {
        this.debug = false

        this.client = new HttpClient()

        this.destinationAccount = DomAccess.querySelector(document, '#destination-account')
        this.destinationTag = DomAccess.querySelector(document, '#destination-tag')
        this.checkPaymentButton = DomAccess.querySelector(document, '#check-payment-button')
        this.spinner = DomAccess.querySelector(this.checkPaymentButton, 'span')

        this.blocks = {
            waiting: this.el.querySelector('[data-ld-live]'),
            expired: this.el.querySelector('[data-ld-expired]'),
            partial: this.el.querySelector('[data-ld-partial]'),
            wrong_asset: this.el.querySelector('[data-ld-wrong-asset]'),
        }
        this.countdown = this.el.querySelector('[data-ld-countdown]')
        this.state = this.el.getAttribute('data-ld-state') || 'waiting'
        this.secondsLeft = parseInt(this.el.getAttribute('data-ld-seconds-left'), 10)
        this.pollUrl = this.el.getAttribute('data-ld-poll-url')

        //this.gemWalletButton = DomAccess.querySelector(document, '#gem-wallet-button')
        //this.crossmarkWalletButton = DomAccess.querySelector(document, '#crossmark-wallet-button')
        //this.xummWalletButton = DomAccess.querySelector(document, '#xumm-wallet-button')

        this.registerEvents()
        this.startCountdown()
        this.schedulePoll()

        setTimeout(this.setupWallets.bind(this), 1000)
    }

    registerEvents() {
        const daCopy = this.destinationAccount.nextElementSibling.firstElementChild
        const daQrcode = this.destinationAccount.nextElementSibling.lastElementChild
        const dtCopy = this.destinationTag.nextElementSibling.firstElementChild
        const dtQrcode = this.destinationTag.nextElementSibling.lastElementChild
        daCopy.addEventListener('click', this.copyToClipboard.bind(this, this.destinationAccount.getAttribute("data-value"), daCopy))
        daQrcode.addEventListener('click', this.showQrCode.bind(this, this.destinationAccount.getAttribute("data-value"), daQrcode))
        dtCopy.addEventListener('click', this.copyToClipboard.bind(this, this.destinationTag.getAttribute("data-value"), dtCopy))
        dtQrcode.addEventListener('click', this.showQrCode.bind(this, this.destinationTag.getAttribute("data-value"), dtQrcode))
        this.checkPaymentButton.addEventListener('click', this.checkPayment.bind(this))
    }

    setupWallets() {
        //setupGemWallet.bind(this)()
        //setupCrossmark.bind(this)()
    }

    /* ---- polling ---- */

    schedulePoll() {
        if (!this.pollUrl) {
            return
        }
        this.pollTimer = setTimeout(this.poll.bind(this), POLL_INTERVAL_MS)
    }

    /**
     * The background poll: no spinner, no disabled button — the customer
     * did not ask for anything.
     */
    poll() {
        this.pollTimer = null
        this.client.get(this.pollUrl, (data, response) => {
            // A failed poll is not worth surfacing — the next one may well
            // work, and the scheduled task is the actual guarantee.
            if (this.applyStatus(this.parse(data, response))) {
                this.schedulePoll()
            }
        }, 'application/json')
    }

    /**
     * The button: the same request, with the spinner while it runs.
     */
    checkPayment() {
        if (this.pollTimer) {
            clearTimeout(this.pollTimer)
            this.pollTimer = null
        }
        this.spinner.style.display = 'inline-block'
        this.checkPaymentButton.disabled = true
        this.client.get(this.pollUrl, (data, response) => {
            this.spinner.style.display = 'none'
            this.checkPaymentButton.disabled = false
            if (this.applyStatus(this.parse(data, response))) {
                this.schedulePoll()
            }
        }, 'application/json')
    }

    parse(data, response) {
        if (response && response.status && response.status !== 200) {
            return null
        }
        try {
            return JSON.parse(data)
        } catch (e) {
            return null
        }
    }

    /**
     * @returns {boolean} whether to keep polling
     */
    applyStatus(payload) {
        if (!payload) {
            return true
        }

        // Whatever ended the wait — settled on-chain, cancelled or paid by
        // hand in the administration — the server sends where to go. That,
        // and only that, stops the polling: a partial payment keeps polling
        // so the top-up is noticed, and an expired quote keeps polling so a
        // late payment is.
        if (payload.redirect) {
            window.location.href = payload.redirect
            return false
        }

        if (payload.state === 'partial' || payload.state === 'wrong_asset') {
            this.fillAmounts(this.blocks[payload.state], payload)
            this.showState(payload.state)
        } else if (payload.state === 'expired') {
            this.secondsLeft = 0
            this.showState('expired')
        } else if (payload.state === 'waiting') {
            // Trust the server's clock over the browser's: it is the one
            // that decides whether the quote still stands.
            if (typeof payload.seconds_left === 'number') {
                this.secondsLeft = payload.seconds_left
            }
            this.showState('waiting')
            this.renderCountdown()
        }

        return true
    }

    /* ---- state blocks ---- */

    showState(nextState) {
        this.state = nextState
        this.el.setAttribute('data-ld-state', nextState)

        Object.keys(this.blocks).forEach((name) => {
            if (this.blocks[name]) {
                this.blocks[name].hidden = name !== nextState
            }
        })
    }

    /* ---- amounts ---- */

    /**
     * The only formatting in this script, and the same rule the server
     * uses: the plain decimal the core states — a native amount arrives as
     * a number, a token amount as an object with a value. Nothing is
     * computed or rounded here; the server already decided what is paid
     * and what is missing.
     */
    formatAmount(amount) {
        if (amount === null || amount === undefined) {
            return ''
        }
        if (typeof amount === 'number') {
            return String(amount)
        }
        return typeof amount.value === 'string' ? amount.value : ''
    }

    fillAmounts(block, payload) {
        if (!block) {
            return
        }
        const paid = block.querySelector('[data-ld-paid]')
        const shortfall = block.querySelector('[data-ld-shortfall]')
        if (paid) {
            paid.textContent = this.formatAmount(payload.amount_paid)
        }
        if (shortfall) {
            shortfall.textContent = this.formatAmount(payload.shortfall)
        }
    }

    /* ---- countdown ---- */

    startCountdown() {
        if (isNaN(this.secondsLeft)) {
            return
        }
        this.renderCountdown()
        setInterval(() => {
            this.secondsLeft -= 1
            this.renderCountdown()
        }, 1000)
    }

    renderCountdown() {
        if (!this.countdown || isNaN(this.secondsLeft)) {
            return
        }

        if (this.secondsLeft <= 0) {
            // Only swaps which block is visible, and only while nothing has
            // arrived: once a payment is in, the partial/wrong-asset block
            // stays and the refresh button must not be offered. The refreshed
            // amount comes from the server on submit — this never recomputes
            // a price in the browser.
            if (this.state === 'waiting') {
                this.showState('expired')
            }
            return
        }

        const minutes = Math.floor(this.secondsLeft / 60)
        const seconds = this.secondsLeft % 60
        this.countdown.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds
    }

    /* ---- copy & QR ---- */

    copyToClipboard(content, icon, event) {
        if (typeof navigator.clipboard === 'undefined') {
            console.warn('Clipboard API not supported - is this a secure context?');

            return;
        }

        const message = 'copied!';
        navigator.clipboard.writeText(content).then(() => {
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

    showQrCode(content, icon) {
        const qr = kjua({
            text: content,
            render: 'image',
            size: 256,
            className: 'qr-code-img',
        })
        qr.classList.add('qr-code-img');
        qr.addEventListener('click', () => document.querySelectorAll('.qr-code-img').forEach(el => el.remove()))
        icon.parentElement.append(qr);
    }

    log(value) {
        if (this.debug) {
            console.warn(value)
        }
    }
}

PluginManager.register(
    'XrpPayment',
    XrpPayment,
    '[data-xrp-payment-page]'
)
