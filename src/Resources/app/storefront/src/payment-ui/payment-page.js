/**
 * LedgerDirect payment page behaviour: count the quote down, ask the server
 * which state the payment is in, switch the server-rendered blocks and fill in
 * the numbers.
 *
 * Framework-free on purpose: only fetch() and the DOM, nothing from the shop.
 * This file is the same for every LedgerDirect plugin; the platform supplies
 * the markup contract described in README.md and starts it with
 * `startPaymentPage(rootElement)`.
 *
 * The page is fully usable without JavaScript: the amount, destination and
 * tag are server-rendered, every state block exists in the markup, the check
 * button is a plain form post, and the platform's safety net settles the order
 * regardless of whether anyone is watching this page.
 *
 * The poll answers with the core's payment-status payload (state, amounts,
 * seconds left) plus a `redirect` once the order no longer waits. This script
 * knows no sentence a customer reads: everything it shows is already in the
 * markup, it only switches blocks and inserts numbers — unrounded, exactly as
 * the server states them.
 */

import { startQr } from './qr';

const POLL_INTERVAL_MS = 8000;
const REDIRECT_DELAY_S = 5;

/**
 * The only "formatting" in this script, and the same rule the server uses:
 * the plain decimal the core states — a native amount arrives as a number,
 * a token amount as an object with a value. Nothing is computed or rounded
 * here; the server already decided what is paid and what is missing.
 */
function plain(amount) {
    if (amount === null || amount === undefined) {
        return '';
    }
    if (typeof amount === 'number') {
        return String(amount);
    }
    return typeof amount.value === 'string' ? amount.value : '';
}

/** Share of the request that arrived, for the progress bar only — never shown as a number. */
function share(paid, requested) {
    const p = parseFloat(plain(paid));
    const r = parseFloat(plain(requested));
    if (!(r > 0) || !(p >= 0)) {
        return 0;
    }
    return Math.max(0, Math.min(100, Math.round((p / r) * 100)));
}

export function startPaymentPage(root) {
    const $ = (selector, scope = root) => scope.querySelector(selector);
    const $$ = (selector, scope = root) => Array.from(scope.querySelectorAll(selector));

    const pollUrl = root.getAttribute('data-ld-poll-url');
    const assetLabel = root.getAttribute('data-ld-asset') || '';
    const quoteSeconds = parseInt(root.getAttribute('data-ld-quote-seconds'), 10);
    const explorerBase = root.getAttribute('data-ld-explorer-base') || '';

    let state = root.getAttribute('data-ld-state') || 'waiting';
    let secondsLeft = parseInt(root.getAttribute('data-ld-seconds-left'), 10);
    let amountDue = $('[data-ld-amount]') ? $('[data-ld-amount]').textContent.trim() : '';
    let pollTimer = null;
    let countdownTimer = null;
    let redirectTimer = null;

    /* ---- state blocks ---- */

    function showState(next) {
        state = next;
        root.setAttribute('data-ld-state', next);
        $$('[data-ld-block]').forEach((el) => {
            el.hidden = el.getAttribute('data-ld-block') !== next;
        });
        $$('[data-ld-status-for]').forEach((el) => {
            el.hidden = el.getAttribute('data-ld-status-for') !== next;
        });
        const label = $('[data-ld-amount-label]');
        if (label) {
            const key = next === 'partial' ? 'remaining' : 'due';
            $$('[data-ld-label]', label).forEach((el) => {
                el.hidden = el.getAttribute('data-ld-label') !== key;
            });
        }
        const fiat = $('[data-ld-fiat]');
        if (fiat) {
            $$('[data-ld-fiat-for]', fiat).forEach((el) => {
                el.hidden = el.getAttribute('data-ld-fiat-for') !== (next === 'partial' ? 'partial' : 'full');
            });
        }
        const qrBox = $('[data-ld-qr-box]');
        if (qrBox) {
            qrBox.classList.toggle('is-void', next === 'expired');
        }
        const qrVoid = $('[data-ld-qr-void]');
        if (qrVoid) {
            qrVoid.hidden = next !== 'expired';
        }
        $$('[data-ld-wallet-section]').forEach((el) => {
            el.hidden = next === 'expired';
        });
        root.dispatchEvent(new CustomEvent('ld:state', { detail: { state: next } }));
    }

    /* ---- amounts ---- */

    function setAmountDue(value) {
        amountDue = value;
        const el = $('[data-ld-amount]');
        if (el) {
            el.textContent = value;
        }
        root.dispatchEvent(new CustomEvent('ld:amount', { detail: { amount: value } }));
    }

    function fillAmounts(block, payload) {
        if (!block) {
            return;
        }
        $$('[data-ld-paid]', block).forEach((el) => {
            el.textContent = plain(payload.amount_paid);
        });
        $$('[data-ld-shortfall]', block).forEach((el) => {
            el.textContent = plain(payload.shortfall);
        });
        $$('[data-ld-progress]', block).forEach((el) => {
            el.style.width = share(payload.amount_paid, payload.amount_requested) + '%';
        });
    }

    /* ---- countdown ---- */

    function renderCountdown() {
        const out = $('[data-ld-countdown]');
        if (!out || Number.isNaN(secondsLeft)) {
            return;
        }
        if (secondsLeft <= 0) {
            // Only swaps which block is visible, and only while nothing has
            // arrived: once a payment is in, the partial/wrong-asset block
            // stays and the refresh button must not be offered. The refreshed
            // amount comes from the server on submit — this never recomputes
            // a price in the browser.
            if (state === 'waiting') {
                showState('expired');
            }
            return;
        }
        const minutes = Math.floor(secondsLeft / 60);
        const seconds = secondsLeft % 60;
        out.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        const bar = $('[data-ld-timer-bar]');
        if (bar && quoteSeconds > 0) {
            bar.style.width = Math.max(0, Math.min(100, (secondsLeft / quoteSeconds) * 100)) + '%';
        }
        const timer = $('[data-ld-timer]');
        if (timer) {
            timer.classList.toggle('is-low', secondsLeft <= 60);
        }
    }

    function startCountdown() {
        clearInterval(countdownTimer);
        if (Number.isNaN(secondsLeft)) {
            return;
        }
        renderCountdown();
        countdownTimer = setInterval(() => {
            secondsLeft -= 1;
            renderCountdown();
        }, 1000);
    }

    /* ---- success and redirect ---- */

    function showSettled(payload) {
        clearInterval(countdownTimer);
        const open = $('[data-ld-open]');
        const success = $('[data-ld-success]');
        if (!success) {
            if (payload.redirect) {
                window.location.href = payload.redirect;
            }
            return;
        }
        if (open) {
            open.hidden = true;
        }
        $$('[data-ld-settled-amount]', success).forEach((el) => {
            el.textContent = plain(payload.amount_paid);
        });
        const hashRow = $('[data-ld-hash-row]', success);
        if (hashRow) {
            // The contract payload carries no hash; it is shown when the platform adds one.
            const hash = typeof payload.hash === 'string' ? payload.hash : '';
            hashRow.hidden = hash === '';
            $$('[data-ld-hash]', hashRow).forEach((el) => {
                el.textContent = hash;
                if (el.tagName === 'A' && explorerBase) {
                    el.href = explorerBase + hash;
                }
            });
        }
        const link = $('[data-ld-redirect-link]', success);
        if (link && payload.redirect) {
            link.href = payload.redirect;
        }
        success.hidden = false;
        root.setAttribute('data-ld-state', 'settled');

        if (!payload.redirect) {
            return;
        }
        let n = REDIRECT_DELAY_S;
        const count = $('[data-ld-redirect-count]', success);
        if (count) {
            count.textContent = String(n);
        }
        clearInterval(redirectTimer);
        redirectTimer = setInterval(() => {
            n -= 1;
            if (count) {
                count.textContent = String(Math.max(0, n));
            }
            if (n <= 0) {
                clearInterval(redirectTimer);
                window.location.href = payload.redirect;
            }
        }, 1000);
    }

    /* ---- polling ---- */

    /**
     * @returns {boolean} whether to keep polling
     */
    function applyStatus(payload) {
        if (!payload) {
            return true;
        }

        if (payload.state === 'settled') {
            showSettled(payload);
            return false;
        }

        // Whatever ended the wait — cancelled or paid by hand in the
        // administration — the server sends where to go. That, and only that,
        // stops the polling: a partial payment keeps polling so the top-up is
        // noticed, and an expired quote keeps polling so a late payment is.
        if (payload.redirect) {
            window.location.href = payload.redirect;
            return false;
        }

        if (payload.state === 'partial' || payload.state === 'wrong_asset') {
            fillAmounts($('[data-ld-block="' + payload.state + '"]'), payload);
            if (payload.state === 'partial') {
                setAmountDue(plain(payload.shortfall));
            }
            showState(payload.state);
        } else if (payload.state === 'expired') {
            secondsLeft = 0;
            showState('expired');
        } else if (payload.state === 'waiting') {
            // Trust the server's clock over the browser's: it is the one
            // that decides whether the quote still stands.
            if (typeof payload.seconds_left === 'number') {
                secondsLeft = payload.seconds_left;
            }
            showState('waiting');
            renderCountdown();
        }

        return true;
    }

    async function requestStatus() {
        const response = await fetch(pollUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) {
            return null;
        }
        return response.json();
    }

    function schedulePoll() {
        if (!pollUrl) {
            return;
        }
        clearTimeout(pollTimer);
        pollTimer = setTimeout(poll, POLL_INTERVAL_MS);
    }

    /** The background poll: no spinner, no disabled button — the customer did not ask for anything. */
    async function poll() {
        pollTimer = null;
        let payload = null;
        try {
            payload = await requestStatus();
        } catch (e) {
            // A failed poll is not worth surfacing — the next one may well
            // work, and the platform's safety net is the actual guarantee.
        }
        if (applyStatus(payload)) {
            schedulePoll();
        }
    }

    /** The check button: the same request with a spinner and, on nothing new, the hint. */
    async function checkNow(event) {
        if (!pollUrl) {
            return; // no JavaScript path: the form posts to the page
        }
        event.preventDefault();
        clearTimeout(pollTimer);
        const button = $('[data-ld-check]');
        const toast = $('[data-ld-toast]');
        const stateBefore = state;
        if (button) {
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;
        }
        $$('[data-ld-check-label]').forEach((el) => {
            el.hidden = el.getAttribute('data-ld-check-label') !== 'busy';
        });
        let payload = null;
        try {
            payload = await requestStatus();
        } catch (e) {
            // answered below like a poll that found nothing new
        }
        if (button) {
            button.removeAttribute('aria-busy');
            button.disabled = false;
        }
        $$('[data-ld-check-label]').forEach((el) => {
            el.hidden = el.getAttribute('data-ld-check-label') !== 'idle';
        });
        const keepPolling = applyStatus(payload);
        if (toast && payload && payload.state === stateBefore && payload.state !== 'settled') {
            toast.hidden = false;
        }
        if (keepPolling) {
            schedulePoll();
        }
    }

    /* ---- copy ---- */

    function copyValue(kind) {
        if (kind === 'amount') {
            return amountDue;
        }
        const source = $('[data-ld-' + kind + ']');
        return source ? (source.getAttribute('data-value') || source.textContent).trim() : '';
    }

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy]');
        if (!button) {
            return;
        }
        const value = copyValue(button.getAttribute('data-copy'));
        if (!value || !navigator.clipboard) {
            return;
        }
        try {
            await navigator.clipboard.writeText(value);
        } catch (e) {
            return;
        }
        button.classList.add('is-done');
        $$('[data-ld-copy-label]', button).forEach((el) => {
            el.hidden = el.getAttribute('data-ld-copy-label') !== 'done';
        });
        setTimeout(() => {
            button.classList.remove('is-done');
            $$('[data-ld-copy-label]', button).forEach((el) => {
                el.hidden = el.getAttribute('data-ld-copy-label') !== 'idle';
            });
        }, 1600);
    });

    /* ---- wiring ---- */

    const checkForm = $('[data-ld-check-form]');
    if (checkForm) {
        checkForm.addEventListener('submit', checkNow);
    }

    const qrDetails = $('[data-ld-qr-details]');
    if (qrDetails && window.matchMedia) {
        const narrow = window.matchMedia('(max-width: 760px)');
        const apply = () => {
            qrDetails.open = !narrow.matches;
        };
        apply();
        narrow.addEventListener('change', apply);
    }

    showState(state);
    startCountdown();
    startQr(root);
    schedulePoll();

    return {
        get state() {
            return state;
        },
        get amountDue() {
            return amountDue;
        },
        checkNow: () => poll(),
    };
}
