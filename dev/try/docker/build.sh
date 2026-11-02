#!/usr/bin/env bash
#
# Image build, once: the Shopware of the dockware image unpacked, the plugin from this checkout
# installed and activated the way a merchant installs it from a checkout or the store zip
# (plugin:install, which lets Composer pull hardcastle/ledger-direct-core into the shop), the demo
# admin login, the storefront theme compiled. The database ends up in the image's /var/lib/mysql;
# the stack's db-data volume starts as a copy of it. Everything that depends on the start (the
# address the shop is reached at, the receiving account, the catalogue) is done by start.sh.
set -euo pipefail
cd /var/www/html

: "${LD_ADMIN_USER:=demo}"
: "${LD_ADMIN_PASSWORD:=demo-ledgerdirect-1}"
log() { printf '[ledger-direct-try build] %s\n' "$*"; }
console() { php bin/console --no-interaction "$@"; }
sql() { mysql -h127.0.0.1 -uroot -proot shopware -N -e "$1" 2>/dev/null; }

log "starting MySQL"
sudo chown -R mysql:mysql /var/lib/mysql /var/run/mysqld
sudo service mysql start >/dev/null

# What dockware's entrypoint does on a container's first start, done here once instead.
if [ -f shopware.tar.zst ]; then
  log "unpacking Shopware"
  sudo zstd -qd shopware.tar.zst -o shopware.tar
  sudo tar -xf shopware.tar -C /var/www/html
  sudo rm shopware.tar.zst shopware.tar
  sudo chown -R 33:33 /var/www/html
fi
log "$(php bin/console --version)"

# The stack mounts volumes on these; created here, so they belong to the web server's user (a volume
# on a path the image lacks is created for root, and Shopware cannot write its thumbnails).
mkdir -p public/media public/thumbnail

# Shopware's dev configuration keeps the app cache in memory for one request; the plugin's sync
# throttle and rate cache would then never hold. The try shop runs in prod (start.sh), where the
# cache is on the file system anyway; this keeps it so when someone switches to dev.
mkdir -p config/packages/dev
printf 'framework:\n    cache:\n        app: cache.adapter.filesystem\n' > config/packages/dev/zz_ledger_direct_cache.yaml

log "installing the plugin"
console plugin:refresh >/dev/null
console plugin:install --activate LedgerDirect
console plugin:list | grep -i ledgerdirect

log "demo admin login"
if [ "$(sql "SELECT COUNT(*) FROM user WHERE username = '${LD_ADMIN_USER}'")" = 0 ]; then
  console user:create "$LD_ADMIN_USER" --admin --password="$LD_ADMIN_PASSWORD" \
    --email=demo@example.invalid --firstName=Demo --lastName=Admin >/dev/null
fi
# dockware's own admin/shopware goes: the try shop has one login, and it says it is a demo.
sql "DELETE FROM user WHERE username = 'admin'"

log "theme and assets"
console theme:compile >/dev/null
console assets:install >/dev/null
console cache:clear >/dev/null

# A clean shutdown, so the database files in the image are consistent.
sudo service mysql stop >/dev/null
rm -rf var/cache/* var/log/*
log "done"
