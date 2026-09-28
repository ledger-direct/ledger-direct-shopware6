# payment-ui — the LedgerDirect payment page, framework-free

`payment-page.js` and `payment-page.css` are the payment page's behaviour and design for **every**
LedgerDirect plugin. They import nothing from Shopware, jQuery or any framework: `fetch()` and the
DOM are all they use, so the same two files can move into a shared package once a second platform
uses them. `main.js` next to this directory is the Shopware hull: it registers a storefront plugin
and calls `startPaymentPage(root)`.

A CI check keeps the boundary: nothing in this directory may mention `PluginManager`, `DomAccess`,
`HttpClient` or import from `src/` (see `tests/Unit/Resources/PaymentUiIsolationTest.php`).

## What the page must be without JavaScript

Every sentence a customer reads is server-rendered. Every state block exists in the markup and the
server decides which one starts visible. The check button is a plain `<form method="post">` back to
the payment page, which syncs on render. The refresh button is a form too. The script only switches
blocks, inserts numbers and polls.

## Markup contract

Root element `.ld-page` with:

| Attribute | Value |
|---|---|
| `data-ld-state` | one of `waiting`, `partial`, `wrong_asset`, `expired` (a settled order never renders the page) |
| `data-ld-poll-url` | the status endpoint for this order, secret included; absent → no polling |
| `data-ld-seconds-left` | integer, only in `waiting` with an expiry |
| `data-ld-quote-seconds` | the quote's full validity, for the countdown bar |
| `data-ld-asset` | the asset label as shown next to amounts (`XRP`, `RLUSD`, `USDC`) |
| `data-ld-network` | `mainnet` or `testnet` |
| `data-ld-explorer-base` | URL prefix a transaction hash is appended to |
| `data-ld-payment-uri` | the payment request the QR code encodes (built by the server; absent → no QR) |
| `data-ld-amount-drops` | XRP only: the requested amount in drops, as a string |
| `data-ld-currency`, `data-ld-issuer` | tokens only: the quoted currency hex code and issuer |
| `data-ld-xaman-key`, `data-ld-wc-project` | optional wallet-app identifiers |

Inside the root:

| Selector | Meaning |
|---|---|
| `[data-ld-open]` | everything shown while the order waits; hidden when the poll reports `settled` |
| `[data-ld-block="<state>"]` | one block per state; the script hides all but the current |
| `[data-ld-status-for="<state>"]` | the status line sentence per state |
| `[data-ld-amount]` | the amount to send; in `partial` the script replaces it with the shortfall |
| `[data-ld-amount-label] [data-ld-label="due|remaining"]` | the two labels above the amount |
| `[data-ld-fiat] [data-ld-fiat-for="full|partial"]` | the fiat line for the two cases |
| `[data-ld-paid]`, `[data-ld-shortfall]`, `[data-ld-progress]` | inside a state block: numbers and the progress bar the poll fills |
| `[data-ld-timer]`, `[data-ld-countdown]`, `[data-ld-timer-bar]` | the countdown |
| `[data-ld-account]`, `[data-ld-tag]`, `[data-ld-issuer]` | copy sources (`data-value` or text) |
| `[data-copy="amount|account|tag|issuer"]` | copy buttons, with `[data-ld-copy-label="idle|done"]` children |
| `[data-ld-check-form]`, `[data-ld-check]`, `[data-ld-check-label="idle|busy"]`, `[data-ld-toast]` | the check button, its labels and the "nothing found yet" hint |
| `[data-ld-qr-details]`, `[data-ld-qr]`, `[data-ld-qr-box]`, `[data-ld-qr-void]` | the QR code, collapsible on narrow screens, blurred in `expired` |
| `[data-ld-wallet-section]` | browser-wallet UI; hidden in `expired` and, unless `[data-ld-wallet-mobile]`, on narrow screens |
| `[data-ld-success]` | the success view: `[data-ld-settled-amount]`, `[data-ld-hash-row]` with `[data-ld-hash]`, `[data-ld-redirect-link]`, `[data-ld-redirect-count]` |

Anchors the end-to-end harness reads and which must stay until it is moved to the attributes above:
`data-ld-state`, `id="xrp-amount"` / `id="token-amount"` (`value` = requested amount),
`id="destination-account"` and `id="destination-tag"` (`data-value`).

## Status payload

`PaymentStatus::toArray()` from the core plus the platform's `redirect`:

```json
{"schema_version":1,"state":"partial","base_asset":"XRP","amount_requested":0.85635,
 "amount_paid":0.5,"shortfall":0.35635,"seconds_left":null}
```

Only a `redirect` or `settled` stops the polling. Amounts are inserted exactly as received —
a number as `String(n)`, a token amount by its `value` — never rounded or reformatted in the browser.

## Events

The root dispatches `ld:state` (`detail.state`) and `ld:amount` (`detail.amount`) so an optional
module (QR rendering, wallets) can follow the page without the page knowing about it.
