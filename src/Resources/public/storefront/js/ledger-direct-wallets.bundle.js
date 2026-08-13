(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(fn, 0);
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function setSpinner(btn, show) {
        if (!btn) return;
        const spinner = qs('span', btn);
        if (spinner) spinner.style.display = show ? 'inline-block' : 'none';
        btn.disabled = !!show;
    }

    async function fetchJSON(url) {
        const r = await fetch(url, {credentials: 'same-origin'});
        if (!r.ok) throw new Error('Request failed: ' + r.status);
        return r.json();
    }

    function toDrops(amountXrp) {
        // amountXrp is a string/number like "12.34" → return string drops
        const n = Number(amountXrp);
        if (!isFinite(n)) throw new Error('Invalid amount: ' + amountXrp);
        return Math.round(n * 1_000_000).toString();
    }

    function encodeMemoData(str) {
        // Hex-encode UTF-8
        const enc = new TextEncoder();
        return Array.from(enc.encode(String(str)))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    async function pollCheck(orderId, onSuccess, onFinally) {
        const deadline = Date.now() + 120_000; // 2 minutes
        const tick = async () => {
            try {
                const res = await fetchJSON('/ledger-direct/payment/check/' + encodeURIComponent(orderId));
                if (res && res.success) {
                    onSuccess && onSuccess(res);
                    return;
                }
            } catch (e) { /* ignore and continue */
            }
            if (Date.now() < deadline) {
                setTimeout(tick, 3000);
            } else {
                onFinally && onFinally();
            }
        };
        tick();
    }

    async function payWithGem(intent, buttons) {
        console.log('payWithGem', intent);
        try {
            setSpinner(buttons.checkBtn, true);

            const amount = typeof intent.amount_requested === 'string' ? toDrops(intent.amount_requested) : intent.amount_requested;

            const payload = {
                destination: intent.destination,
                destinationTag: intent.destinationTag,
                amount: amount,
            };
            console.log('GemWallet payload:', payload);

            window.GemWalletApi.sendPayment(payload).then((response) => {
                console.log('GemWallet response:', response);
                const hash = response && response.result && response.result.hash;
                if (hash) {
                    // Start polling using existing check endpoint
                    pollCheck(intent.orderId, () => {
                        window.location.reload();
                    }, () => setSpinner(buttons.checkBtn, false));
                } else {
                    console.warn('No hash returned from GemWallet', response);
                    setSpinner(buttons.checkBtn, false);
                }
            }, (reason) => {
                console.error('GemWallet payment failed (reason)', reason)
                setSpinner(buttons.checkBtn, false)
            }).catch((e) => {
                console.error('GemWallet payment failed (error)', e);
                setSpinner(buttons.checkBtn, false);
            });
        } catch (e) {
            console.error('GemWallet payment failed', e);
            setSpinner(buttons.checkBtn, false);
            alert('GemWallet payment failed: ' + (e && e.message ? e.message : e));
        }
    }

    async function payWithCrossmark(intent, buttons) {
        if (!window.crossmark) {
            alert('Crossmark not found');
            return;
        }
        console.log('payWithCrossmark', intent);
        try {
            setSpinner(buttons.checkBtn, true);
            // Get account/address
            const addrRes = await window.crossmark.request({method: 'xrpl_getAddress'});
            const account = (addrRes && addrRes.result) || addrRes;

            const tx = {
                TransactionType: 'Payment',
                Account: account,
                Destination: intent.destination,
                Amount: typeof intent.amount === 'string' ? toDrops(intent.amount) : intent.amount
            };
            if (intent.destinationTag) tx.DestinationTag = Number(intent.destinationTag);
            if (intent.memo) {
                tx.Memos = [{Memo: {MemoData: encodeMemoData(intent.memo)}}];
            }

            // Sign and submit
            const signRes = await window.crossmark.request({
                method: 'xrpl_signAndSubmit',
                params: {tx_json: tx}
            });

            const r = signRes && signRes.result ? signRes.result : signRes;
            const hash = r && (r.tx_hash || r.hash);
            if (hash) {
                pollCheck(intent.orderId, () => {
                    window.location.reload();
                }, () => setSpinner(buttons.checkBtn, false));
            } else {
                console.warn('No hash returned from Crossmark', signRes);
                setSpinner(buttons.checkBtn, false);
            }
        } catch (e) {
            console.error('Crossmark payment failed', e);
            setSpinner(buttons.checkBtn, false);
            alert('Crossmark payment failed: ' + (e && e.message ? e.message : e));
        }
    }

    function waitForGlobal(path, opts) {
        const {interval = 150, timeout = 10000, immediate = true} = opts || {};
        const resolvePath = (p) => p.split('.').reduce((o, k) => (o ? o[k] : undefined), window);

        return new Promise((resolve, reject) => {
            const start = Date.now();
            let t;

            const check = () => {
                const val = resolvePath(path);
                if (val !== undefined && val !== null) {
                    clearInterval(t);
                    resolve(val);
                } else if (Date.now() - start >= timeout) {
                    clearInterval(t);
                    reject(new Error(`waitForGlobal(${path}) timeout`));
                }
            };

            t = setInterval(check, interval);
            if (immediate) check();

            // Also try on window load (some extensions attach late)
            window.addEventListener('load', check, {once: true});
        });
    }

    ready(async function () {
        const host = document.querySelector('[data-xrp-payment-page]');
        if (!host) return;

        const gemBtn = document.getElementById('gem-wallet-button');
        const cmBtn = document.getElementById('crossmark-wallet-button');
        const checkBtn = document.getElementById('check-payment-button');

        const orderId = checkBtn && checkBtn.dataset.orderId;
        if (!orderId) return;

        let intent;
        try {
            intent = await fetchJSON('/ledger-direct/payment/xrpl-intent/' + encodeURIComponent(orderId));
            console.log(intent);
        } catch (e) {
            console.error('Failed to fetch XRPL intent', e);
            return;
        }

        waitForGlobal('GemWalletApi', {interval: 200, timeout: 15000})
            .then(async () => {
                const isInstalled = await window.GemWalletApi.isInstalled();
                if (isInstalled && isInstalled.result && isInstalled.result.isInstalled) {
                    gemBtn.classList.remove('wallet-disabled');
                    gemBtn.addEventListener('click', () => payWithGem(intent, {checkBtn}));
                }
            })
            .catch((err) => {
                console.error('[GemWalletApi] waitForGlobal failed', err);
                // Optional: add more context

                console.log('GemWalletApi timeout f')
                // still not found after timeout;
            });


        waitForGlobal('crossmark', {interval: 200, timeout: 15000})
            .then(() => {
                cmBtn.classList.remove('wallet-disabled');
                cmBtn.addEventListener('click', () => payWithCrossmark(intent, {checkBtn}));
            })
            .catch(() => {
                console.log('crossmark timeout f')
                // still not found after timeout;
            });
    });
})();
