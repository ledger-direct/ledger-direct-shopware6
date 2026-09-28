/**
 * The QR code: one code with the payment request the server put into
 * `data-ld-payment-uri`. This module only renders; the request itself is
 * built and unit-tested on the server (PaymentUri), so every platform
 * encodes the same thing.
 *
 * When a partial payment turns the amount to send into the shortfall, the
 * page announces the new amount (`ld:amount`) and the request is rewritten
 * with it — a string replacement of the `amount` parameter, not arithmetic.
 * A request without an amount stays without one.
 */
import qrcode from 'qrcode-generator';

const CELL_SIZE = 4;

function withAmount(uri, amount) {
    let url;
    try {
        url = new URL(uri);
    } catch (e) {
        return uri;
    }
    if (!url.searchParams.has('amount') || !amount) {
        return uri;
    }
    url.searchParams.set('amount', amount);
    return url.toString();
}

function render(host, text, label) {
    const code = qrcode(0, 'M');
    code.addData(text);
    code.make();
    host.innerHTML = code.createSvgTag({ cellSize: CELL_SIZE, margin: 0, scalable: true });
    const svg = host.querySelector('svg');
    if (svg) {
        svg.setAttribute('role', 'img');
        if (label) {
            svg.setAttribute('aria-label', label);
        }
    }
}

export function startQr(root) {
    const host = root.querySelector('[data-ld-qr]');
    let uri = root.getAttribute('data-ld-payment-uri') || '';
    if (!host || !uri) {
        return;
    }
    const label = host.getAttribute('data-ld-qr-label') || '';

    render(host, uri, label);

    root.addEventListener('ld:amount', (event) => {
        const next = withAmount(uri, event.detail && event.detail.amount);
        if (next !== uri) {
            uri = next;
            root.setAttribute('data-ld-payment-uri', uri);
            render(host, uri, label);
        }
    });
}
