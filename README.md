# LedgerDirect Payment plugin for Shopware

LedgerDirect is a payment plugin for Shopware. Receive crypto and stablecoin payments directly – without middlemen,
intermediary wallets, extra servers or external payment providers. Maximum control, minimal detours!

Project Website: https://www.ledger-direct.com

GitHub: https://github.com/ledger-direct/ledger-direct-shopware

![Payment Page](payment_page.png)

## Installation

### Manual Installation

1. Installation via git/CLI: In `custom/plugins`, use execute`git clone https://github.com/ledger-direct/ledger-direct-shopware6.git LedgerDirect`.
2. Manually: Downloading the .zip archive of this plugin (`Code -> Download ZIP`) and extract its contents into `custom/plugins/LedgerDirect`.
3. Refresh Shopware plugin list: `bin/console plugin:refresh`
4. Install and activate the plugin: `bin/console plugin:install LedgerDirect --activate`
5. Clear the cache: `bin/console cache:clear`

### Configuration
1. Configure the basic settings like receiving wallet address in the Shopware admin under "Settings" > "Extensions" > "My Extensions" > "LedgerDirect".
2. Enable LedgerDirect XRP / RLUSD payment methods in "Settings" > "Shop" > "Payment Methods".
3. Set the LedgerDirect payment methods as available for your sales channels.

## Available currencies:
- XRP (XRP Ledger)
- RLUSD (XRP Ledger)

To receive stablecoin payments, ensure you have the corresponding currencies (RLUSD, USDC etc.) enabled in the plugin settings.
The merchant wallet address needs to have the corresponding trust lines set up for the stablecoins you want to accept.

## Test Payments
To test the plugin, you can configure it to use the XRP Ledger Testnet. This allows you to simulate transactions without using real funds. Follow these steps:
1. Go to the extension settings in Shopware admin ("Settings" > "Extensions" > "My Extensions" > "LedgerDirect").
2. Enable the Testnet mode.
3. Use a test XRP Ledger account to make test payments.
4. You can create test accounts from https://xrpl.org/xrp-testnet-faucet.html for XRP or https://tryrlusd.com/ for RLUSD.

## External Services
LedgerDirect uses public APIs from Coinbase, Coingecko, Binance, and Kraken to retrieve current cryptocurrency exchange rates. These rates are needed to correctly calculate and display payments.

No personal or payment data is sent to these services. Only requests for current rates are made when a payment is processed or displayed.

For more information about each service, see:
- Coinbase API: [Terms of Service](https://www.coinbase.com/legal/user_agreement), [Privacy Policy](https://www.coinbase.com/legal/privacy)
- Coingecko API: [Terms of Service](https://www.coingecko.com/en/terms), [Privacy Policy](https://www.coingecko.com/en/privacy)
- Binance API: [Terms of Use](https://www.binance.com/en/terms), [Privacy Policy](https://www.binance.com/en/privacy)
- Kraken API: [Terms of Service](https://www.kraken.com/legal), [Privacy Policy](https://www.kraken.com/privacy)

## License
The MIT License (MIT). Please see [License File](LICENSE) for more information.
