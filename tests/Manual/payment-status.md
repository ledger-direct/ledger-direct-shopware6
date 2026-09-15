# Manual test cases — payment status, Shopware

The Shopware instantiation of the core's case catalogue,
`docs/manual-tests/payment-status.md` in `hardcastle/ledger-direct-core`. The catalogue says
*what* must hold on every platform; this file says how to make it happen in this shop: which
setting, which command, which state name, where the evidence shows up. Same IDs.

**How this is used.** A pull request that touches the payment page, `PaymentRoute`,
`OrderTransactionService`, `PaymentStateService` or the scheduled task lists the applicable IDs
under "Manual end-to-end tests" as checkboxes and ticks what was actually run, with the order
number or hash next to it. Results are not collected in this file; the PR is the record. Should
a case get automated or become a merge gate one day, it keeps its ID.

## Environment

- The dockware container `shopware6_672-shopware-1`; commands below run inside it as `www-data`:
  `docker exec -u www-data shopware6_672-shopware-1 bash -lc '…'`.
- **Own testnet wallet** for this shop (never the one another shop uses — shared tag space):
  `POST https://faucet.altnet.rippletest.net/accounts`, then trust lines to the testnet issuers
  of RLUSD and USDC from the core's `StablecoinRegistry`. Seed outside the repo
  (`Shopware6_6.7.2/.env` in the harness folder), never in docs or commits.
- Plugin configuration: Settings → Extensions → My extensions → LedgerDirect: *Use Testnet* on,
  the testnet wallet as *Merchant Wallet Address (Testnet)*, RLUSD and USDC enabled,
  *Quote validity* 300 s unless a case says otherwise.
- The payment page: `/ledger-direct/payment/{orderId}?deepLinkCode=…&returnUrl=…`. The poll it
  makes: `/ledger-direct/payment/check/{orderId}?deepLinkCode=…&returnUrl=…` (the same payload as
  `/store-api/ledger-direct/payment/check/{orderId}`). Watch it in the browser's network tab, or
  call it with `curl -H 'Accept: application/json'`.
- Transaction state: Orders → order → *Payment status*, or

  ```sql
  SELECT o.order_number, s.technical_name
  FROM order_transaction t
  JOIN `order` o ON o.id = t.order_id AND o.version_id = t.order_version_id
  JOIN state_machine_state s ON s.id = t.state_id
  WHERE o.order_number = '10015';
  ```

  The state history: Orders → order → *Status history*. Open states are `open`, `in_progress`,
  `unconfirmed`, `paid_partially`, `reminded` (`PaymentStateService::OPEN_STATES`).
- Log lines at info level (the scheduled task's summary) only appear with `APP_ENV=dev`, in
  `var/log/dev.log`; errors also on the console.

## PS-01 — Waiting

Place an order with *XRP*, send nothing, open the payment page.

Look for: `data-ld-state="waiting"` on the page wrapper; the countdown next to *This amount is
guaranteed for*; a request to `/ledger-direct/payment/check/…` every 8 s in the network tab, each
answering `"state":"waiting"` with a falling `seconds_left` and no `redirect`; the transaction
`unconfirmed` or `open`.

## PS-02 — Expired, then refreshed

*Quote validity* 60 s in the plugin configuration. Place an XRP order, send nothing, wait a minute
with the page open.

Look for: the countdown block hidden, the expired notice with *Get an updated amount* visible;
the poll answers `"state":"expired"`, `"seconds_left":null`, still no `redirect`. Click the
button (a `POST /ledger-direct/payment/refresh/{orderId}`): the page reloads with a new amount and
a fresh countdown, the destination tag unchanged. Reset the validity afterwards.

Also: the button is refused (plain redirect back, no new quote) when something has arrived —
try it on the PS-03 order.

## PS-03 — Partial, then topped up

Place a small XRP order. Send half the displayed amount to account and tag. Do **not** reload.

Look for, within 8 s: the `data-ld-partial` block visible with *X XRP received so far, Y XRP is
still outstanding*; the poll answers `"state":"partial"` with `amount_paid` and `shortfall`; the
transaction **`paid_partially`** in the administration while the page is still open. Then send
`Y` to the same account and tag: the poll answers `redirect`, the page goes to the finish page,
the transaction is `paid`; in `order_transaction.custom_fields` → `ledger_direct.hash` is the
second transaction's hash and `amount_paid` the sum.

## PS-04 — Wrong asset, then the right one

Place a *USDC* order. Pay the full amount in **RLUSD** to account and tag.

Look for: the `data-ld-wrong-asset` block visible naming the RLUSD amount and the full USDC
request; the poll answers `"state":"wrong_asset"` with `amount_paid.issuer` the RLUSD issuer and
`shortfall` in USDC with the full value; the transaction `paid_partially`. Then send the USDC:
`redirect`, `paid`, and `ledger_direct.hash` is the USDC transaction. (Order 10014 in the dev
database is a standing example of the first half.)

## PS-05 — Settled

Place an XRP order of about 1.00 EUR. Send exactly the amount the page shows.

Look for: `redirect` in the next poll, the finish page, transaction `paid`; in *Status history*
exactly **one** transition to *Paid* (not one from the status endpoint and another from the
return over the `returnUrl`).

## PS-06 — Guest, key knowledge instead of login

Place the order as a guest. Copy the payment page URL (or the order link from the confirmation
mail, which carries the same `deepLinkCode`). Open it in a private window.

Look for: the payment page renders without a login prompt; the poll URL opened in the same window
answers 200 with the payload.

## PS-07 — Wrong key is refused without a hint

```
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost/ledger-direct/payment/check/{orderId}?deepLinkCode=wrong'
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost/ledger-direct/payment/check/00000000000000000000000000000000?deepLinkCode=wrong'
```

Look for: 403 both times, same body shape. The payment page with a wrong code redirects to
`/account/order` in both cases too.

## PS-08 — Throttling

Two open orders on the testnet wallet, two payment pages open in two browsers (or one page plus
`curl` on the poll URL twice within 5 s).

Look for: both polls answer the full payload. The node request count: the core does not log each
`account_tx` call, so measure it either with the scheduled task's summary (PS-09,
`accounts_synced`) or, for the page, by timing — a poll that synced takes noticeably longer
(about a second) than one answered from the stored intent; only one of two calls within 5 s does.

## PS-09 — Safety net without a browser

Place an XRP order, close the page, send the full amount. Then:

```bash
APP_ENV=dev php bin/console scheduled-task:run-single ledger_direct.settle_open_transactions
APP_ENV=dev php bin/console messenger:consume async low_priority --time-limit=20 --limit=5
grep 'scheduled settlement run' var/log/dev.log | tail -1
```

Look for: the transaction `paid` without any page having been open, and the line

```
app.INFO: LedgerDirect: scheduled settlement run {"accounts_synced":1,"checked":N,"settled":1}
```

`accounts_synced` is the number of `account_tx` requests the run made: one per distinct account
and network, whatever `checked` is. With one order placed on testnet and one on mainnet
(switch the configuration in between) it reads `2`. An order from before the core retrofit
("Missing schema_version") is logged as an error and skipped every run; that is expected.

## PS-10 — Late return after the payment token expired

Place an XRP order, leave the page open, wait 35 minutes (the token in the `returnUrl` lives
30), then send the full amount.

Look for: the poll answers `"state":"settled"` with a `redirect` to `/account/order/{deepLinkCode}`
instead of the `returnUrl`; following it shows the order, no "token expired" error; the
transaction `paid`.

## PS-11 — Closed by the merchant

Place an XRP order, send nothing, keep the page open. In the administration set the transaction
to *Cancelled*.

Look for: the next poll carries a `redirect` while `state` is still `waiting`; the page leaves.
Then send the amount anyway: the transaction stays `cancelled` (neither the scheduled task nor a
return over the `returnUrl` reopens it).
