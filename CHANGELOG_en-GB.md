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
  Values are inserted URL-encoded; a template without `{deepLinkCode}` or not an absolute URL is refused
  on save. Nothing changes for a sales channel without the settings
- README: a section "Headless / external front end" on the Store API, the redirect targets and the
  payment page package's `headers` option

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
