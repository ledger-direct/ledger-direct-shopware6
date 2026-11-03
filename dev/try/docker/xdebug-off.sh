# Replaces dockware's scripts/bin/xdebug_disable.sh in the try image. The image ships with Xdebug off
# already; dockware's script restarts PHP-FPM regardless, twice per boot (once from `make switch-php`,
# once from the entrypoint), and each restart waits out start-stop-daemon's retries — about two minutes
# of a first start. This one restarts only when it actually switched Xdebug off, and otherwise just
# makes sure PHP-FPM runs.
echo "DOCKWARE: deactivating Xdebug..."
V=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")
changed=0
for sapi in fpm cli; do
  if [ -f "/etc/php/$V/$sapi/conf.d/20-xdebug.ini" ]; then
    sudo mv "/etc/php/$V/$sapi/conf.d/20-xdebug.ini" "/etc/php/$V/$sapi/conf.d/20-xdebug.ini_disabled"
    changed=1
  fi
done
if [ "$changed" = 1 ]; then
  sudo service "php$V-fpm" restart > /dev/null 2>&1
else
  sudo service "php$V-fpm" start > /dev/null 2>&1 || true
fi
