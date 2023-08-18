# LedgerDirect - Shopware6

## An integration of the [XRPL](https://xrpl.org/) for [Shopware 6](https://github.com/shopware/platform) 

## Installation

1. Installation via git/CLI: In `custom/plugins`, use execute`git clone https://github.com/ledger-direct/ledger-direct-shopware6.git LedgerDirect`.
2. Manually by downloading the .zip archive of this plugin (`Code -> Download ZIP) and extracting its contents into ``custom/plugins/LedgerDirect`.
3. Refresh Shopware plugin list: `php bin/console plugin:refresh`
4. GMP installieren: `apt install php8.1-gmp`
5. XRPL_PHP installieren: `composer require hardcastle/xrpl_php`
6. Install and activate the plugin: `bin/console plugin:install LedgerDirect --activate`
7. Clear the cache: `bin/console cache:clear`

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.