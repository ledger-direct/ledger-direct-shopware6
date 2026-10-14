<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

/**
 * Where a transaction hash can be looked up, per network.
 *
 * The core knows no explorers; this is the plugin's mapping, the same two
 * URLs the Magento, PrestaShop and WooCommerce plugins use. Moves to the
 * core once it gets a chain-wide answer to the question (Stellar asks it
 * too).
 */
final class Explorer
{
    private const TRANSACTIONS = [
        'mainnet' => 'https://livenet.xrpl.org/transactions/',
        'testnet' => 'https://testnet.xrpl.org/transactions/',
    ];

    public static function transactionUrl(string $network, ?string $hash): ?string
    {
        $base = self::TRANSACTIONS[$network] ?? null;

        return $base === null || $hash === null || $hash === '' ? null : $base . $hash;
    }
}
