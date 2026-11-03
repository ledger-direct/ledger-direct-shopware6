#!/usr/bin/env bash
#
# Smoke test of a running try stack (dev/try/compose.yaml), the way a visitor would use it: the
# shop answers, the miniatures are on the home page and in their category, the demo article is in
# its category, a guest order with XRP gets a payment page that renders the markup contract of
# @ledger-direct/payment-ui, and the status endpoint answers the payment-status contract with
# `waiting` (case PS-01 of the core's catalogue) and refuses a wrong key (PS-07).
#
#   dev/try/smoke.sh [base url]          default http://localhost:8091
#
# The order goes through the Store API, as a headless client places it. Needs curl and jq. Exits
# non-zero on the first failure.
set -euo pipefail
BASE=${1:-http://localhost:${LD_TRY_PORT:-8091}}
BASE=${BASE%/}
ACCESS_KEY=${LD_ACCESS_KEY:-SWSCLEDGERDIRECTTRYSHOP000}
NUMBER=ledger-direct-demo
XRP=7ca60321a9d2dac0fe3622a5110f55bb

pass() { printf 'ok    %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*" >&2; exit 1; }
store() {
  local method=$1 path=$2 body=${3:-}
  curl -fsS -X "$method" -H "sw-access-key: $ACCESS_KEY" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    ${CONTEXT:+-H "sw-context-token: $CONTEXT"} ${body:+-d "$body"} "$BASE$path"
}

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
[ "$code" = 200 ] || fail "storefront answered $code"
pass "storefront answers"

# Read the page first: `curl | grep -q` under pipefail fails whenever grep exits before curl has
# written everything (SIGPIPE), even though the text is there.
category=$(curl -fsS "$BASE/ledger-direct-demo/")
grep -q 'LedgerDirect demo article' <<<"$category" || fail "the demo category does not list the demo article"
pass "demo category lists the demo article"

# The miniatures from dev/try/catalog/catalog.json, in their category and on the home page, in
# English and in German.
catalog="$(dirname "$0")/catalog/catalog.json"
for language in en-GB de-DE; do
  prefix=$([ "$language" = de-DE ] && echo /de || true)
  miniatures=$(curl -fsS "$BASE$prefix/miniatures/")
  home=$(curl -fsS "$BASE$prefix/")
  while IFS= read -r name; do
    html_name=$(sed 's/&/\&amp;/g' <<<"$name")
    grep -qF "$html_name" <<<"$miniatures" || fail "the Miniatures category ($language) lacks \"$name\""
    grep -qF "$html_name" <<<"$home" || fail "the home page ($language) lacks \"$name\""
  done < <(jq -r --arg l "$language" '.products[] | (.[$l] // ."en-GB").name' "$catalog")
  if grep -qF 'LedgerDirect demo article' <<<"$home"; then fail "the demo article is on the home page ($language)"; fi
done
pass "home page and the Miniatures category show $(jq '.products | length' "$catalog") miniatures, in English and German"

# A guest order with XRP for the demo article, through the Store API.
product=$(store POST /store-api/product "{\"filter\":[{\"type\":\"equals\",\"field\":\"productNumber\",\"value\":\"$NUMBER\"}],\"limit\":1}" | jq -r '.elements[0].id // empty')
[ -n "$product" ] || fail "no product $NUMBER in the storefront"
salutation=$(store GET /store-api/salutation | jq -r '.elements[0].id')
country=$(store POST /store-api/country '{"filter":[{"type":"equals","field":"iso","value":"DE"}]}' | jq -r '.elements[0].id')
CONTEXT=$(curl -fsS -o /dev/null -D - -X POST -H "sw-access-key: $ACCESS_KEY" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"guest\":true,\"storefrontUrl\":\"$BASE\",\"salutationId\":\"$salutation\",\"firstName\":\"Try\",\"lastName\":\"Visitor\",\"email\":\"visitor@example.invalid\",\"acceptedDataProtection\":true,\"billingAddress\":{\"street\":\"1 Testnet Way\",\"zipcode\":\"10115\",\"city\":\"Berlin\",\"countryId\":\"$country\"}}" \
  "$BASE/store-api/account/register" | tr -d '\r' | awk -F': ' 'tolower($1) == "sw-context-token" { print $2 }')
[ -n "$CONTEXT" ] || fail "guest registration returned no context token"
store POST /store-api/checkout/cart/line-item "{\"items\":[{\"type\":\"product\",\"referencedId\":\"$product\",\"quantity\":1}]}" >/dev/null
store PATCH /store-api/context "{\"paymentMethodId\":\"$XRP\"}" >/dev/null
order=$(store POST /store-api/checkout/order '{}')
read -r id number deep total < <(jq -r '"\(.id) \(.orderNumber) \(.deepLinkCode) \(.amountTotal)"' <<<"$order")
[ -n "$id" ] && [ "$id" != null ] || fail "placing the order failed: $order"
[ "$total" = 1 ] || fail "the order costs $total EUR instead of 1 EUR"
redirect=$(store POST /store-api/handle-payment "{\"orderId\":\"$id\",\"finishUrl\":\"$BASE/checkout/finish?orderId=$id\",\"errorUrl\":\"$BASE/account/order/edit/$id\"}" | jq -r '.redirectUrl // empty')
[ -n "$redirect" ] || fail "handle-payment gave no payment page"
pass "guest order $number placed with XRP, total $total EUR"

# The payment page under the URL handle-payment answered; the returnUrl in it carries Shopware's
# payment token, which the page and the status endpoint pass along.
case $redirect in http*) page_url=$redirect ;; *) page_url=$BASE$redirect ;; esac
query=${page_url#*\?}
page=$(mktemp)
code=$(curl -s -o "$page" -w '%{http_code}' "$page_url")
[ "$code" = 200 ] || fail "payment page answered $code"
for attribute in 'data-ld-page' 'data-ld-state="waiting"' 'data-ld-amount-requested="' 'data-ld-asset="XRP"' 'data-ld-poll-url="' 'data-ld-account' 'data-ld-tag'; do
  grep -q -- "$attribute" "$page" || fail "payment page lacks $attribute"
done
amount=$(grep -o 'data-ld-amount-requested="[^"]*"' "$page" | head -1 | cut -d'"' -f2)
pass "payment page renders the markup contract (amount $amount XRP)"

status=$(curl -fsS -H 'Accept: application/json' "$BASE/ledger-direct/payment/check/$id?$query")
[ "$(jq -r .state <<<"$status")" = waiting ] || fail "status endpoint: $status"
[ "$(jq -r '.seconds_left > 0' <<<"$status")" = true ] || fail "status endpoint without seconds_left: $status"
pass "status endpoint: waiting, $(jq -r .seconds_left <<<"$status") s left (PS-01)"

code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$BASE/ledger-direct/payment/check/$id?deepLinkCode=wrong")
[ "$code" = 403 ] || fail "a wrong key got $code instead of 403"
pass "a wrong key is refused with 403 (PS-07)"

printf '\npayment page: %s\n' "$page_url"
