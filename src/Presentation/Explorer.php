<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

/**
 * Where a transaction hash can be looked up, per chain and network.
 *
 * The core knows no explorers; this is the plugin's mapping — the XRPL
 * ones the Magento, PrestaShop and WooCommerce plugins use too, and
 * stellar.expert for Stellar. Moves to the core once it gets a chain-wide
 * answer to the question.
 */
final class Explorer
{
    private const TRANSACTIONS = [
        'XRPL' => [
            'mainnet' => 'https://livenet.xrpl.org/transactions/',
            'testnet' => 'https://testnet.xrpl.org/transactions/',
        ],
        'STELLAR' => [
            'mainnet' => 'https://stellar.expert/explorer/public/tx/',
            'testnet' => 'https://stellar.expert/explorer/testnet/tx/',
        ],
    ];

    /** The URL prefix a hash is appended to; '' for a chain or network without an explorer here. */
    public static function transactionBase(string $chain, string $network): string
    {
        return self::TRANSACTIONS[$chain][$network] ?? '';
    }

    public static function transactionUrl(string $chain, string $network, ?string $hash): ?string
    {
        $base = self::transactionBase($chain, $network);

        return $base === '' || $hash === null || $hash === '' ? null : $base . $hash;
    }
}
