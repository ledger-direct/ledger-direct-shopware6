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
