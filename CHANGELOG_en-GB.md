# Unreleased
- Needs `hardcastle/ledger-direct-core` 0.13.
- In preparation for refunds, the update creates two tables, `ledger_direct_xrpl_refund` and
  `ledger_direct_xahau_refund`. Nothing writes to them yet and nothing in the shop changes. They are
  kept on uninstall, like the destination-tag counter: a refund's row will hold what is nowhere on the
  ledger (which order a payment was for, who confirmed where the money goes back to, whether the refund
  is booked), and without it a payment could be refunded in full a second time.
- An XRP Ledger or Xahau node that reports success without a list of transactions counts as a failed
  sync, no longer as "nothing new" (core 0.13). The payment page goes on with what is stored, and
  "Refresh" on the order says that the list may not be up to date. A working node always sends the list.

# 1.12.0
- The setting "WalletConnect project ID" explains where the ID comes from (dashboard.reown.com) and
  links to the new README section "Setting up WalletConnect": account, project, domain allowlist and a
  check, step by step. Every shop needs its own project ID.
- The card "Received payments" on the order detail has a "Refresh" button. The list shows what the
  shop has synced, and once a transaction is no longer open only the scheduled task syncs its
  receiving account: a second payment on a paid order, or a payment on a cancelled attempt, appeared
  up to five minutes late. The button asks the ledger for the receiving accounts of all LedgerDirect
  transactions of the order (those in their payment records, so an earlier receiving account is
  covered), throttled like every sync, and shows the list again. A transaction that is still open
  gets its state from what was found, and the LedgerDirect card above loads again. If a node cannot
  be reached, the card shows what is stored and says that the list may not be up to date.
  `POST /api/_action/ledger-direct/order/{orderId}/payments/refresh` answers the list plus `sync`
  (`synced`, `throttled`, `failed`), for whoever may read the order
- The payment page uses @ledger-direct/payment-ui 0.6.0 instead of 0.5.0.
  - **WalletConnect works and has its own button.** With a WalletConnect project id, "Pay with a
    wallet app (WalletConnect)" sits above "Pay with a browser wallet", on every screen width, and
    opens WalletConnect's dialog with the code to scan. Before, the click showed nothing to scan.
    The wallet app is told the shop's name and favicon instead of the host name. The phone's "Open in
    wallet app" button is Xaman's alone now; before, it opened WalletConnect when no Xaman key was set.
  - **Address and destination tag as QR codes of their own,** in a dialog next to their copy buttons,
    for wallets whose scanner fills one field at a time (Joey Wallet reads no payment request).
  - The QR heading names Xaman on the XRP Ledger and on Xahau ("Scan with Xaman"), and so does the
    caption on the XRP Ledger; Stellar keeps "Scan with a wallet app". The caption is centred under the
    code, the "or" between the alternatives is larger, the wallet message is centred.
  - Only ledger-direct.js and the stylesheet copy change in the build; the other chunks are
    byte-identical.
- The payment page uses @ledger-direct/payment-ui 0.5.0 instead of 0.4.2. Joey Wallet's browser
  extension is offered in the wallet list when it is installed; it connects on the page's network,
  pays nothing on another, and a connection Joey ends (access revoked, account gone, network
  switched) is forgotten, so the next click asks Joey again. A wallet error (another network, a
  wallet that does not respond, a failed payment) now shows as a warning box instead of grey text
  under the hint, where it went unseen. Only ledger-direct.js and the stylesheet copy change; the
  wallet chunk and the other chunks are byte-identical.
- After a customer changes the payment method of an order (in the account under "Change payment
  method", or with the second try after a failed payment), the payment page shows the new payment.
  Before, page and status check looked at the order's first transaction, which Shopware cancels on a
  change: a customer who changed from XRP to RLUSD, or from prepayment to XRP, was sent straight back
  to the shop and could not reach the payment page of the new attempt. A browser tab that still shows
  the payment from before the change moves on to the new one at its next status check, so nobody pays
  to the address and tag of a cancelled attempt; after a change to a payment method that is not
  LedgerDirect's it goes to the order. The page's address and its status check name the transaction
  they are for (`orderTransactionId`); the Store API routes `check`, `quote` and `refresh` follow the
  current transaction too and take the same parameter. A change to a LedgerDirect payment method
  needs "Allow payment change after checkout" switched on for that method, which the plugin leaves
  off; a change from LedgerDirect to one of Shopware's methods is possible in every shop
- The order detail lists the received ledger payments of an order: a card "Received payments" under
  the LedgerDirect card (tab Details), with one row per payment for every LedgerDirect transaction of
  the order: when it arrived, sender, amount, and the transaction as a link to the explorer. Cancelled
  and failed transactions are listed too, also when the order's current transaction belongs to
  another payment method, so a payment that arrived late is found. A payment in another asset than
  the requested one is listed and marked as not credited; payments on another network (testnet and
  mainnet share account and tag) are left out. Read from what the shop has synced;
  `GET /api/_action/ledger-direct/order/{orderId}/payments` answers it for whoever may read the order
- The LedgerDirect card on the order detail shows its rows while nothing has arrived, too. For an
  order that waits for its payment or whose quote has expired, the card stopped at the state label:
  the row "Quote valid until" called a date filter the component did not have
- The LedgerDirect card on the order detail ignores an answer that arrives late for another
  transaction. When the order detail moved on to another transaction while the card was still
  loading, the earlier answer could arrive last and show that transaction's payment under the
  current one
- A payment record with a field of the wrong type (a destination tag that is empty or text, say)
  counts as unreadable, like one with a field missing: the LedgerDirect card says it cannot read the
  record, and "Received payments" names it and lists the other transactions. Before, the core's type
  error went through: the card said it could not load, and the list failed as a whole. The checkout
  still stops on such a record instead of quoting over it: its account and tag may be intact and
  already paid to
- For shops with an external front end: the Store API's `quote` and `refresh` name the transaction
  they answer for, as `orderTransactionId`. A front end that sends it back on `check` is told when the
  customer has changed the payment method since: the status then carries a `redirect` to the payment
  page, where a new `quote` is the new payment. Without it, a payment page left open kept showing the
  address, tag and amount of the cancelled payment while its status was the new one's. The storefront's
  own page does this already. The field is new; nothing else in the answers changes

# 1.11.1
- The payment page uses @ledger-direct/payment-ui 0.4.2 instead of 0.4.0, which fixes partial
  payments in the wallet flow: after a partial payment, a wallet button opens the payment for the
  rest instead of reloading the page again and again; an XRP payment whose amount changed while the
  wallet was connecting reloads instead of signing the old amount; a page that turned paid or
  expired meanwhile signs nothing; and an older status answer arriving late no longer turns the page
  back. After payment the page goes to the order confirmation once: clicking the button while the
  countdown ran out sent the customer to an error page (the order was paid). The storefront bundle
  is rebuilt, its stylesheet unchanged

# 1.11.0
- Needs `hardcastle/ledger-direct-core` 0.12.0. The checkout offers only what the receiving account
  can take. A token the account cannot receive (no trust line, frozen, full, not authorized) is left
  out, so no order exists that nobody can pay. An XRP Ledger or Xahau account that is not activated
  yet keeps the native asset, whose first payment activates it. When the node cannot be reached,
  tokens are left out and the native asset stays
- Stellar: an account that does not exist is offered nothing, XLM included. A Stellar payment never
  creates its destination
- Each chain's configuration card shows what the saved receiving account lacks, next to the options,
  which stay switchable
- Every customer and merchant text in six languages: English, German, French, Dutch, Spanish and
  European Portuguese. French, Dutch, Spanish and Portuguese are new, for the storefront, the
  administration, the configuration and the payment method names. The terms follow the LedgerDirect
  glossary (German: "Herausgeber" for issuer, "Kasse" for checkout, "Kurs", "Kursgültigkeit")
- The message for an order that cannot be paid with LedgerDirect is translated; it was English
- The checkout shows the icons of the LedgerDirect payment methods. The template sat at a path the
  storefront never includes, so 6.6 and 6.7 showed none

# 1.10.2
- Needs `hardcastle/ledger-direct-core` 0.11.3. The payment request in the QR code and behind the
  Xaman button now names its network on the XRP Ledger too, as it already did on Xahau. Before,
  Xaman paid on whichever network the wallet had open, so an XRP payment scanned with a Xahau
  account opened a XAH payment of the same amount
- The payment page uses @ledger-direct/payment-ui 0.4.0 instead of 0.3.0: the wallet library is
  xrpl-connect 1.0.0 with xrpl 5 instead of the release candidate 1.0.0-rc.2. Among its changes, the
  Xaman wallet submits the payment to the ledger under its own control, and wallet availability
  checks time out instead of hanging. The storefront bundle is rebuilt; its stylesheet is unchanged

# 1.10.1
- Needs `hardcastle/ledger-direct-core` 0.11.1. When the price oracles disagree, the plugin gets no price
  instead of a stale rate
- A testnet reset, such as the one scheduled for the XRP Ledger testnet on 2026-11-25, costs one failing
  request per receiving account on the XRP Ledger and on Xahau: the sync remembers the reset in the
  shop's cache. Before, every sync of an account with payments from before the reset started with a
  failing request, a warning in the log and a full download of the account's history
- When no price can be had for a new quote, the customer gets a way to try again instead of an error
  page. "Request a new amount" on an expired payment page keeps the page as it was, with its button,
  and says the price is temporarily unavailable. The Store API's `payment/refresh` answers
  `409 LEDGER_DIRECT__PRICE_UNAVAILABLE` instead of 500 and leaves the order's quote unchanged. At
  checkout the payment fails as before, and the order's payment page now says why and that trying
  again in a moment may work. `ledger-direct:xrp-price:lookup` prints an error line instead of a
  stack trace (#64)

# 1.10.0
- The LedgerDirect card on the order detail is back on Shopware 6.7. Since 1.7.0 the built
  administration files named a script the release did not contain: `.vite/entrypoints.json` and
  `manifest.json` still pointed to `ledger-direct-rTdLB6k2.js` after the build had replaced it with
  `ledger-direct-BK50jI7P.js`, so the administration requested a file that answered 404 and the
  plugin's part of it never loaded. The manifest names the shipped file again, and a test checks
  that the entry points name files that are shipped and that the built script carries the snippets
  of every chain
- Xahau: XAH on the [Xahau](https://xahau.network) network, a chain of its own that speaks the XRP
  Ledger's protocol, paid by destination tag. A configuration card "LedgerDirect - Xahau" right under
  the XRP Ledger's, off by default; its payment method is created with the plugin. The QR code names the
  Xahau network, so Xaman offers to switch if it is set to another one; the wallet buttons stay off on a
  Xahau page. Xahau has its own tables (`ledger_direct_xahau_tx`, `ledger_direct_xahau_destination_tag`,
  created by a migration) and shares nothing with the XRP Ledger, also for an account that exists on
  both. Needs `hardcastle/ledger-direct-core` 0.11
- `ledger-direct:xrpl-transaction:sync` and `ledger-direct:xrpl-transaction:lookup` take `--chain XAHAU`
- A preview chain runs on its testnet only: switching the testnet toggle of the Stellar card off is
  refused when saved, as a mistyped account is, and an installation whose Stellar card already says
  mainnet offers nothing there; its open orders keep settling where they were paid
- `e2e.yml` runs the case catalogue on Xahau too, after the XRP Ledger, from a CI treasury of its own
  (`LEDGERDIRECT_CI_XAHAU_TREASURY_SEED` / `_ADDRESS`)
- The Stellar preview's XLM and EURC payment methods have their icon at checkout; they showed a broken
  image
- Try it in five minutes: `dev/try` starts a complete Shopware 6.7 shop with the plugin on the XRP Ledger
  testnet (`docker compose up -d --wait`): a home page in English and German that explains the demo and
  offers three hand-painted miniatures, a demo article for 1 EUR in a category of its own, the shop's own
  testnet wallet with trust lines for RLUSD and USDC, and a demo admin login. A CI job builds it on every
  pull request, installs the plugin into a fresh Shopware the way a merchant does, smoke-tests the payment
  page and the status endpoint and walks the storefront in a real browser, from the home page to the
  payment page, with XRP, RLUSD and USDC. The miniatures' pictures and texts are not under the MIT
  licence (`dev/try/catalog/NOTICE.md`); the store zip and the Composer package leave `dev` out
- The payment page's poll (`/ledger-direct/payment/check/{orderId}`) refuses with JSON in every
  environment: `403 {"error":"forbidden"}` when neither the key nor the customer's session authorises the order,
  `404 {"error":"no_payment_intent"}`
  for an order never quoted for XRPL — the same bodies as the Magento and PrestaShop plugins. In `prod`
  Shopware answered both with its HTML error page. The payment page never read that body, so nothing
  changes for customers; a client that parses the refusal now gets JSON. The Store API route is unchanged

# 1.9.0
- Store API for headless front ends, second part. `check` carries `hash` once a payment is on record:
  the payment page's success view shows the transaction with its explorer link — it had a row for that
  since 1.4.0, which no platform ever filled — and a front end's order page can show it too
- `POST /store-api/ledger-direct/payment/refresh/{orderId}`: a current quote, re-quoting an order whose
  quote has expired — the "quote expired" button of a front end's payment page. An order with a payment on
  record, short or in the wrong asset, gets its current quote back unchanged; account and tag stay the same
  unless the receiving account was changed in between
- `quote` hands an expired quote out as the page shows it, with `page.state` `expired`, instead of
  answering 410 (1.8.0); 410 stays for an order that no longer waits for a payment. A front end builds the
  same expired page as the storefront and refreshes from there
- The three routes are in the Store API's OpenAPI document (`/store-api/_info/openapi3.json`), with the
  schemas of the quote, the payment page values and the payment status, so API clients can be generated
  from it

# 1.8.0
- Store API for headless front ends, first part: `GET /store-api/ledger-direct/payment/quote/{orderId}`
  answers a guest who sends the order's `deepLinkCode`, the way `check` always has, instead of
  requiring a logged-in customer. A wrong code and another customer's order get the same 403, an
  order never quoted with LedgerDirect 404, an expired quote or an order that no longer waits for a payment 410 (was 500)
- The `quote` payload gains `page` (`schema_version` 1): every value the payment page is built from,
  for XRP, RLUSD, USDC and the Stellar assets alike — state, seconds left, amounts as the page shows
  them, account, tag or memo, issuer and currency code, the payment request for the QR code, explorer
  base and the wallet-app identifiers. The storefront's own payment page renders from the same value
  set (`PaymentPageView`), so the two cannot drift apart. The flat fields of `quote` stay as they were
- The `price` route is removed; it was a placeholder answering `{"todo": "implement"}` since the first
  release. What it was meant for, `quote` answers
- A card "LedgerDirect - External front end" in the configuration, per sales channel, empty by default:
  the URL of the front end's payment page (`{orderId}`, `{deepLinkCode}`, `{returnUrl}`), which
  `handle-payment` answers instead of the storefront's page, and the URL of its order page
  (`{orderId}`, `{deepLinkCode}`), where a payment arriving after the payment token has expired lands.
  Values are inserted URL-encoded; a template without `{deepLinkCode}` or not an absolute `https://` URL (plain `http://` only for
  localhost) is refused on save. Nothing changes for a sales channel without the settings
- README: a section "Headless / external front end" on the Store API, the redirect targets and the
  payment page package's `headers` option
- Payment page package 0.3.0 (the `headers` option for the poll); the storefront's behaviour is unchanged

# 1.7.0
- Stellar as the second chain, **as a preview** (core 0.10): XLM, USDC and EURC payment methods, paid
  by memo id, synced from Horizon, settled under the same rules as on the XRP Ledger. A card
  "LedgerDirect - Stellar (Preview)" in the configuration, off by default; nothing of Stellar shows
  anywhere else until it is switched on. Switching it on creates the Stellar payment methods, which
  appear at checkout once a receiving account is set. Not for production use yet
- An issued Stellar asset (USDC, EURC) is offered at checkout only while the receiving account has a
  trust line to its issuer; without one the payment would fail in the customer's wallet
- The payment page says "Memo ID" instead of "Destination tag" on Stellar, the QR code carries a SEP-7
  payment request, the transaction links to stellar.expert. The browser-wallet section is XRPL-only
  (payment page package 0.2.0)
- The order detail's LedgerDirect card shows the memo id and the stellar.expert link for a Stellar order
- Console commands take `--chain XRPL|STELLAR`; the price lookup takes `--asset`
- Two new tables, `ledger_direct_stellar_payment` and `ledger_direct_stellar_memo`, created on update

# 1.6.0
- The configuration is built for more than one chain. The XRP Ledger card gets a switch "Accept
  payments on the XRP Ledger" (on for every installation that has a receiving account configured); a
  second chain gets a card of its own with the same shape. Nothing was renamed, no setting has to be
  entered again
- Payment methods whose chain is switched off, whose receiving account is missing or whose asset is
  disabled no longer appear at checkout. Until now a disabled stablecoin was still offered and failed
  when the customer chose it
- A receiving address is checked the moment it is saved: an address that is not an account on that
  chain is refused with a message; a stray space or line break around a pasted address is removed
- Core 0.10: the payment methods, their assets and the chain's address format come from the core's
  `ChainCatalog`; the plugin no longer carries its own list. Under the hood one payment handler per
  chain instead of one per asset; the three XRP Ledger payment methods keep their ids, names and
  handler identifiers
- Core 0.10: a payment on the other network — a testnet payment for a mainnet order — never settles
  an order and never shows as a wrong-token payment
- Groundwork for Stellar (XLM, USDC, EURC), which follows in 1.7.0

# 1.5.2
- Store validation: a migration no longer reads a column name through a Doctrine DBAL class that
  Shopware 6.8 marks as deprecated. No change in behaviour

# 1.5.1
- Core 0.9: the synced transactions store the CTID in the column `tx_ctid` instead of `ctid`, the
  name every LedgerDirect plugin uses from now on (PostgreSQL reserves `ctid` for a system column).
  A migration renames the column in place on update; nothing changes for customers or merchants
- Core 0.9: a transaction whose CTID is malformed is skipped during sync, and logged, instead of
  being stored truncated

# 1.5.0
- A LedgerDirect card on the order detail (tab Details, under the transaction card): the payment's
  state, what was quoted (asset, amount, rate, receiving account, destination tag, issuer, quote
  validity), what arrived — naming the other token and its issuer when it was the wrong one —, what is
  still due, and the transaction as a link to the XRPL explorer. Read-only; computed on the server by
  the new admin route `/api/_action/ledger-direct/payment-info/{orderTransactionId}` (`order:read`)
- The scheduled task syncs the configured receiving account on every run, so a payment on an order
  the merchant has already cancelled is on record too

# 1.4.4
- Core 0.8.1: a payment in the other asset class — a token sent for an XRP order, or XRP for a token
  order — is reported as a wrong-token payment with the full amount still due (transaction
  `paid_partially`), instead of being ignored. Before, the page stayed on "waiting" while the money sat
  on the ledger

# 1.4.3
- Core 0.8: the payment request behind the QR code (`PaymentUri`) and the accent-colour rule
  (`AccentColor`) come from the shared core library now; the plugin's copies are gone. No change
  for customers or merchants

# 1.4.2
- The payment page's behaviour and design now come from the shared package `@ledger-direct/payment-ui`
  (0.1.1), the same code every LedgerDirect plugin renders; the local copy is gone. The page's root
  carries the requested amount as `data-ld-amount-requested`; the legacy ids `xrp-amount`,
  `token-amount`, `destination-account` and `destination-tag` are gone. No change for customers

# 1.4.1
- Housekeeping after 1.4.0: dead files removed (Cypress remnants, an unused exception class), lint
  warnings cleared in the payment page scripts, the store zip now ships the committed storefront
  bundle as it is instead of building it a second time. No change in behaviour

# 1.4.0
- The payment page is redesigned: the amount to send is the largest thing on the page and has a
  copy button, the fiat amount sits below it; the receiving address, the destination tag (marked
  as required, with its hint right there) and, for tokens, the issuer are numbered fields with
  copy buttons; a countdown in minutes and seconds with a bar; one column on phones; dark mode
  follows the system; no web font is loaded
- One QR code with the receiving address, the destination tag and the amount (and, for tokens,
  currency and issuer) — typing the tag was the biggest source of lost payments. Verified with
  Xaman on the testnet: address, tag and amount are taken over as shown
- Browser wallets over XRPL Connect: Crossmark, GemWallet, MetaMask Snap, Ledger, Otsu and Xyra
  are offered when detected; Xaman and WalletConnect when the merchant enters their public
  identifier in the new "Payment page" configuration card. The wallet library is loaded only
  when the wallet list is opened
- New configuration card "Payment page": shop logo, a picture from the media library or a
  monogram; an accent colour (a colour too light for white text falls back to the default)
- A partial payment turns the outstanding amount into the main amount with a progress bar; an
  expired amount is struck through and cannot be copied; a settled payment shows a confirmation
  before the redirect
- Fixed: the issuer was never shown although the wrong-token notice referred to it; the
  destination-tag hint said "XRP" for every asset; the exchange rate read backwards
- The check button works without JavaScript
- The checkout's payment-method icons now exist; the stray "change payment method" link is gone
- The page's behaviour and design are framework-free (payment-ui/) and will be shared with the
  other LedgerDirect plugins

# 1.3.0
- Guest orders can be paid: the payment page and the payment-status endpoint no longer require a
  login. The order's own link code — the one behind the guest order link in the confirmation mail —
  opens them, so a guest who has just placed an order sees the payment instructions instead of a
  login page, and the same link still works an hour later in a fresh browser
- The payment page shows one of five states — waiting with a countdown, expired with a button for
  an updated amount, partially paid, paid in the wrong token, paid — and polls the status every
  eight seconds; it no longer reloads, and only leaves once the order is paid or was closed
- Two partial payments add up: a customer who sends the outstanding amount settles the order
- The merchant sees a partial payment or a payment in the wrong token as "Paid partially" in the
  administration the moment it arrives, and "Paid" the moment the order is settled — the
  customer no longer has to return to the shop for that, and a payment token that expired in the
  meantime no longer leaves a paid order open
- The ledger is synced at most once every five seconds per receiving account, however many
  customers are waiting on their payment pages
- The status endpoint answers with the same payload as every other LedgerDirect plugin
  (`schema_version`, `state`, `base_asset`, `amount_requested`, `amount_paid`, `shortfall`,
  `seconds_left`) plus a `redirect` once the order no longer waits
- Requires hardcastle/ledger-direct-core 0.7

# 1.2.0
- Destination tags are issued from a random starting point per receiving account instead of always
  from zero, so two shops sharing one wallet no longer hand out the same tags — which previously let
  one shop's payment settle another shop's order
- Which transaction on a destination tag pays an order is now decided by asset class rather than by
  row order: a stray payment in the other asset no longer blocks the real one
- The sync cursor is kept per receiving account and network, and recovers on its own after an XRPL
  testnet reset instead of failing every sync until the table is cleared by hand
- The payment page now explains a payment that arrived but does not settle the order — a token from
  the wrong issuer, or an amount that falls short — including how much is still outstanding
- Fixed the XRP amount on the payment page: the call to action rounded the quote to two decimals
  while it is quoted with five, so paying exactly what the page asked for could leave the order
  unsettled — most reliably on small orders
- Fixed the German payment page, which printed the placeholder instead of the amount
- Requires hardcastle/ledger-direct-core 0.4

# 1.1.0
- Shopware 6.7 compatibility: payment handlers migrated to the new `AbstractPaymentHandler` API
- Fixed Doctrine DBAL 4 parameter types and replaced removed `fetchAll()` with `fetchAllAssociative()`
- Fixed storefront controller (removed obsolete `setTwig` service call)
- Fixed QR code library import in the storefront JavaScript
- Corrected payment currency/asset metadata: added `base_asset` and `quote_currency`, fixed `pairing` to reflect the actual asset
- Removed unused `TokenPaymentHandler`
- Pricing, XRPL access, transaction sync and destination tags now come from the shared
  `hardcastle/ledger-direct-core` library instead of plugin-local copies
- Payment records follow the cross-plugin schema v1: `version` becomes `schema_version`,
  `delivered_amount` becomes `amount_paid`, and a quote now carries an `expiry`
- Destination tags are issued from an atomic per-account counter and use XRPL's full
  unsigned 32-bit range; `destination_tag` and `ledger_index` columns were widened accordingly
- Exchange rates are cached in Shopware's object cache, so a brief oracle outage no longer
  interrupts checkout
- New settings: RLUSD/USDC can be switched off, and the validity of a price quote is configurable
- Fixed the testnet/mainnet switch, which read a setting key that was never stored and therefore
  always stayed on testnet
- Whether a ledger payment settles an order is now decided by the core's `SettlementPolicy` (XRP within
  0.15 %, tokens exactly from the quoted issuer); a same-named token from another issuer no longer counts
  as paid. Requires `hardcastle/ledger-direct-core` 0.2

# 1.0.0
- Initial release: accept XRP, RLUSD and USDC payments directly on the XRP Ledger
