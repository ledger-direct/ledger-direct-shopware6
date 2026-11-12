# syntax=docker/dockerfile:1.7
#
# LedgerDirect for Shopware 6 — try-it image.
#
# dockware's Shopware 6.7 (Shopware, MySQL, Apache and PHP in one container, the same image the CI
# tests against) with the plugin from this checkout installed and activated, on the XRP Ledger
# testnet. The catalogue, the receiving account and the address the shop is reached at are set up
# on every start; see docker/start.sh.
#
# dockware is MIT-licensed (https://github.com/dockware/dockware), Shopware's Community Edition MIT;
# the image carries further software under its own licences (MySQL, Apache, PHP, …). This is not an
# official Shopware or dockware image.
#
# Build from the repository root:
#   docker build -f dev/try/Dockerfile -t try-shopware .

# Base images by tag and digest, written out in the FROM lines: Dependabot (docker) keeps them
# current only there. The base.name label below repeats the tag.
FROM dockware/shopware:6.7.16.0@sha256:9b2d23f778479c3bfed436f27e60a2a5978ed8d503972bc5670bbd84255ad379
ARG LD_VERSION=dev
# gmp for the wallet tool's signing (hardcastle/xrpl_php), for the PHP version the image runs.
RUN sudo apt-get update -qq \
 && sudo apt-get install -y -qq --no-install-recommends "php$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')-gmp" >/dev/null \
 && sudo rm -rf /var/lib/apt/lists/*

# The plugin as the store zip carries it (see .shopware-extension.yml): the code, composer.json and
# the documents, none of the development files.
COPY --chown=33:33 composer.json LICENSE.md README.md CHANGELOG_en-GB.md CHANGELOG_de-DE.md /var/www/html/custom/plugins/LedgerDirect/
COPY --chown=33:33 src /var/www/html/custom/plugins/LedgerDirect/src
COPY --chown=33:33 dev/try/docker/build.sh /usr/local/share/ledger-direct/build.sh
RUN bash /usr/local/share/ledger-direct/build.sh

# The shop's testnet wallet tool (hardcastle/xrpl_php), in a vendor/ of its own: it runs as a separate
# process, so its dependencies never meet Shopware's.
COPY --chown=33:33 dev/try/wallet/composer.json /opt/ledger-direct/wallet/composer.json
RUN COMPOSER_HOME=/tmp/composer composer install --quiet --no-interaction --no-progress --no-dev --working-dir=/opt/ledger-direct/wallet \
 && rm -rf /tmp/composer

# dockware's virtual hosts without its developer tools (Adminer, log viewer, Mailcatcher), and Xdebug
# off in the image with a switch that does not restart PHP-FPM for nothing (docker/xdebug-off.sh).
COPY dev/try/docker/apache.conf /tmp/000-default.conf
COPY --chown=33:33 dev/try/docker/xdebug-off.sh /var/www/scripts/bin/xdebug_disable.sh
RUN sudo mv /tmp/000-default.conf /etc/apache2/sites-enabled/000-default.conf \
 && sudo chown root:root /etc/apache2/sites-enabled/000-default.conf \
 && V=$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;') \
 && for sapi in fpm cli; do \
      f=/etc/php/$V/$sapi/conf.d/20-xdebug.ini; [ ! -f "$f" ] || sudo mv "$f" "${f}_disabled"; \
    done

COPY --chown=33:33 dev/try/docker/start.sh dev/try/docker/setup.php dev/try/docker/wallet.php /usr/local/share/ledger-direct/
# The miniatures (catalog.json and pictures; their licence is in dev/try/catalog/NOTICE.md).
COPY --chown=33:33 dev/try/catalog/catalog.json dev/try/catalog/NOTICE.md /usr/local/share/ledger-direct/catalog/
COPY --chown=33:33 dev/try/catalog/images /usr/local/share/ledger-direct/catalog/images
# What identifies this build to an existing shop: Shopware's resolved packages and the plugin. When it
# differs from the one the shop was set up with (an updated image on existing volumes), start.sh runs
# the update. The same for the catalogue: a new one is imported into an existing shop on its next start.
# /var/www/ledger-direct-try is the wallet's volume; created here, so the volume belongs to www-data.
RUN (cat /var/www/html/composer.lock; cd /var/www/html/custom/plugins/LedgerDirect && find . -type f | sort | xargs cat) \
      | sha256sum | cut -c1-16 > /usr/local/share/ledger-direct/build-id \
 && (cd /usr/local/share/ledger-direct/catalog && cat catalog.json images/* | sha256sum | cut -c1-16) \
      > /usr/local/share/ledger-direct/catalog-id \
 && printf '#!/bin/sh\nexec bash /usr/local/share/ledger-direct/start.sh\n' > /var/www/boot_end.sh \
 && mkdir -p /var/www/ledger-direct-try && chmod 0700 /var/www/ledger-direct-try

LABEL org.opencontainers.image.title="LedgerDirect for Shopware 6 — try it" \
      org.opencontainers.image.description="Shopware 6.7 (dockware) with the LedgerDirect XRPL payment plugin, on the XRP Ledger testnet. For trying the plugin, not for production." \
      org.opencontainers.image.source="https://github.com/ledger-direct/ledger-direct-shopware6" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${LD_VERSION}" \
      org.opencontainers.image.base.name="dockware/shopware:6.7.14.2"
# The scheduled tasks and the message queue run from the container's cron: the plugin's settlement
# task settles orders whose payment arrived while no payment page was open.
ENV SW_TASKS_ENABLED=1 \
    XDEBUG_ENABLED=0
EXPOSE 80 443
# Healthy once dockware's entrypoint, start.sh included, has finished and the shop answers.
HEALTHCHECK --interval=5s --timeout=5s --start-period=600s --retries=3 \
  CMD test -f /var/www/container.launched && curl -fs -o /dev/null http://127.0.0.1/api/_info/health-check || exit 1
