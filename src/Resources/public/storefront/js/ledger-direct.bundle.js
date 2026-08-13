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

    function createToast(message, isError) {
        const oldToast = document.querySelector('.copy-toast');
        if (oldToast) oldToast.remove();

        const toast = document.createElement('div');
        toast.classList.add('copy-toast');
        toast.textContent = message;
        toast.style.backgroundColor = isError ? '#f44336' : '#1daae6';
        return toast;
    }

    function copyToClipboard(content, icon) {
        if (typeof navigator.clipboard === 'undefined') {
            console.warn('Clipboard API not supported.');
            const toast = createToast('Clipboard unsupported', true);
            icon.parentElement.append(toast);
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
            return;
        }

        navigator.clipboard.writeText(content).then(() => {
            const toast = createToast('copied!', false);
            icon.parentElement.append(toast);
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }).catch(err => {
            console.error('Failed to copy: ', err);
            const toast = createToast('Failed to copy to clipboard', true);
            icon.parentElement.append(toast);
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        });
    }

    function showQrCode(content, icon) {
        // Prefer global kjua if available (loaded via CDN or vendored file)
        if (typeof window.kjua !== 'function') {
            console.warn('window.kjua not found, QR code disabled.');
            return;
        }
        const qr = window.kjua({
            text: content,
            render: 'image',
            size: 256,
            className: 'qr-code-img'
        });
        qr.classList.add('qr-code-img');
        qr.addEventListener('click', () => document.querySelectorAll('.qr-code-img').forEach(el => el.remove()));
        icon.parentElement.append(qr);
    }

    function setSpinner(btn, show) {
        const spinner = qs('span', btn);
        if (!spinner) return;
        spinner.style.display = show ? 'inline-block' : 'none';
        btn.disabled = !!show;
    }

    function initPaymentPage(root) {
        const container = root || document;

        const destinationAccount = document.getElementById('destination-account');
        const destinationTag = document.getElementById('destination-tag');
        const checkPaymentButton = document.getElementById('check-payment-button');

        if (!destinationAccount || !destinationTag || !checkPaymentButton) {
            // Nothing to initialize
            return;
        }

        // Wire actions for Destination Account
        try {
            const daFuncs = destinationAccount.nextElementSibling;
            const daCopy = daFuncs && daFuncs.firstElementChild;
            const daQr = daFuncs && daFuncs.lastElementChild;
            if (daCopy) {
                daCopy.addEventListener('click', () => copyToClipboard(destinationAccount.getAttribute('data-value'), daCopy));
            }
            if (daQr) {
                daQr.addEventListener('click', () => showQrCode(destinationAccount.getAttribute('data-value'), daQr));
            }
        } catch (e) {
            console.warn('Failed binding destination account actions', e);
        }

        // Wire actions for Destination Tag
        try {
            const dtFuncs = destinationTag.nextElementSibling;
            const dtCopy = dtFuncs && dtFuncs.firstElementChild;
            const dtQr = dtFuncs && dtFuncs.lastElementChild;
            if (dtCopy) {
                dtCopy.addEventListener('click', () => copyToClipboard(destinationTag.getAttribute('data-value'), dtCopy));
            }
            if (dtQr) {
                dtQr.addEventListener('click', () => showQrCode(destinationTag.getAttribute('data-value'), dtQr));
            }
        } catch (e) {
            console.warn('Failed binding destination tag actions', e);
        }

        // Check payment polling
        checkPaymentButton.addEventListener('click', () => {
            const orderId = checkPaymentButton.dataset.orderId;
            if (!orderId) return;
            setSpinner(checkPaymentButton, true);

            fetch('/ledger-direct/payment/check/' + encodeURIComponent(orderId), {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            })
                .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || 'Request failed'); }))
                .then(result => {
                    if (result && result.success) {
                        window.location.reload();
                    } else {
                        setSpinner(checkPaymentButton, false);
                    }
                })
                .catch(err => {
                    console.error('Payment check failed', err);
                    setSpinner(checkPaymentButton, false);
                });
        });
    }

    ready(function () {
        // Initialize only on pages that have the data attribute
        var host = document.querySelector('[data-xrp-payment-page]');
        if (host) {
            initPaymentPage(document);
        }
    });
})();