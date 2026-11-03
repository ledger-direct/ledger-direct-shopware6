#!/usr/bin/env bash
#
# Every start of the try-it shop, run by dockware's entrypoint once MySQL and Apache are up
# (/var/www/boot_end.sh); the container reports healthy only after this has finished.
# - the address the shop is reached at and its environment (.env.local)
# - after an image update: Shopware's update steps, the plugin's update, theme and assets
# - the LedgerDirect settings (receiving account, payment methods, domains) — on every start, so a
#   changed LD_TESTNET_ACCOUNT or LD_TRY_PORT takes effect without a reset
# - when the image brings a catalogue the shop has not seen yet: categories, articles, home page
#
# The shop's state lives in the stack's volumes (database, media, thumbnails, the wallet), so
# recreating this container keeps it. `docker compose down -v` is the reset.
set -euo pipefail
cd /var/www/html

: "${LD_BASE_URL:=http://localhost:8091}"
: "${LD_TLS_URL:=}"
: "${LD_APP_ENV:=prod}"
# A demo login for a shop that only listens on this machine. Not a secret, never reuse it.
: "${LD_ADMIN_USER:=demo}"
: "${LD_ADMIN_PASSWORD:=demo-ledgerdirect-1}"
# The storefront's Store API key. Not a secret either: every storefront page carries it.
: "${LD_ACCESS_KEY:=SWSCLEDGERDIRECTTRYSHOP000}"
export LD_BASE_URL LD_TLS_URL LD_ADMIN_USER LD_ADMIN_PASSWORD LD_ACCESS_KEY

SETUP=/usr/local/share/ledger-direct
STATE=/var/www/ledger-direct-try
log() { printf '[ledger-direct-try] %s\n' "$*"; }
console() { php bin/console --no-interaction "$@"; }
sql() { mysql -h127.0.0.1 -uroot -proot shopware -N -e "$1" 2>/dev/null; }
# What this shop was last set up with, kept in its own database: the database and the marker can
# never disagree, whatever happens to the container or the other volumes.
marker() { sql "SELECT JSON_UNQUOTE(JSON_EXTRACT(configuration_value, '\$._value')) FROM system_config WHERE configuration_key = 'LedgerDirectTry.$1' AND sales_channel_id IS NULL"; }
set_marker() { console system:config:set "LedgerDirectTry.$1" "$2" >/dev/null; }
started=$(date +%s)

# Shopware builds media and asset URLs from APP_URL; prod as a real shop runs (no profiler bar,
# the HTTP cache on). Rewritten on every start: the container's file system is the image's.
printf 'APP_ENV=%s\nAPP_URL=%s\n' "$LD_APP_ENV" "${LD_BASE_URL%/}" > .env.local

for _ in $(seq 1 60); do
  curl -fs -o /dev/null http://127.0.0.1/api/_info/health-check && break
  sleep 2
done

build=$(marker buildId)
if [ -z "$build" ]; then
  # The database is the image's own (a first start): nothing to update.
  set_marker buildId "$(cat "$SETUP/build-id")"
elif [ "$build" != "$(cat "$SETUP/build-id")" ]; then
  log "the image changed since the last start: updating the shop"
  console system:update:finish --skip-asset-build >/dev/null
  console plugin:refresh >/dev/null
  console plugin:update LedgerDirect >/dev/null
  console theme:compile >/dev/null
  console assets:install >/dev/null
  set_marker buildId "$(cat "$SETUP/build-id")"
fi

php "$SETUP/setup.php" config

if [ "$(marker catalogId)" != "$(cat "$SETUP/catalog-id")" ]; then
  php "$SETUP/setup.php" catalog
  # Thumbnails for pictures whose files are missing (a media volume that is newer than the database).
  console media:generate-thumbnails --strict >/dev/null 2>&1 || true
  set_marker catalogId "$(cat "$SETUP/catalog-id")"
fi
# What the writes left in the message queue; the container's cron (SW_TASKS_ENABLED) takes over from here.
console messenger:consume async low_priority --time-limit=15 >/dev/null 2>&1 || true
console cache:clear >/dev/null

# The first storefront request warms the prod cache; done here, so the visitor's is fast.
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${LD_BASE_URL#*://}" http://127.0.0.1/ || true)
[ "$code" = 200 ] || { log "the storefront answered ${code}"; exit 1; }

log "ready in $(( $(date +%s) - started )) s"
log "shop:  ${LD_BASE_URL%/}/  (German: ${LD_BASE_URL%/}/de/)"
log "admin: ${LD_BASE_URL%/}/admin  (demo login ${LD_ADMIN_USER} / ${LD_ADMIN_PASSWORD} — local demo only)"
log "Store API access key: ${LD_ACCESS_KEY}"
log "receiving account: $(cat "$STATE/account" 2>/dev/null || echo unknown)"
