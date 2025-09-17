# LedgerDirect Payment plugin for Shopware

LedgerDirect is a payment plugin for Shopware. Receive crypto and stablecoin payments directly – without middlemen,
intermediary wallets, extra servers or external payment providers. Maximum control, minimal detours!

Project Website: https://www.ledger-direct.com

Plugin URL: placeholder for now, will be available in the Shopware Plugin Store after Beta.

GitHub: https://github.com/ledger-direct/ledger-direct-shopware


## Installation

### Manual Installation

1. Installation via git/CLI: In `custom/plugins`, use execute`git clone https://github.com/ledger-direct/ledger-direct-shopware6.git LedgerDirect`.
2. Manually by downloading the .zip archive of this plugin (`Code -> Download ZIP) and extracting its contents into ``custom/plugins/LedgerDirect`.
3. Refresh Shopware plugin list: `php bin/console plugin:refresh`
4. Ggf. Paketverwaltung auf den neuesten Stand bringen: `apt-get update`
5. GMP installieren: `apt install php8.1-gmp`
6. XRPL_PHP installieren: `composer require hardcastle/xrpl_php`
7. Install and activate the plugin: `bin/console plugin:install LedgerDirect --activate`
8. Clear the cache: `bin/console cache:clear`

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.