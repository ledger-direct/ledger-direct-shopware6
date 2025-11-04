<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

use Hardcastle\LedgerDirect\Service\ConfigurationService;

class StablecoinProvider
{
    public const RLUSD_CODE = 'RLUSD';

    private const RLUSD_SETTINGS = [
        'mainnet' => [
            'issuer' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            'currency' => '524C555344000000000000000000000000000000',
        ],
        'testnet' => [
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
            'currency' => '524C555344000000000000000000000000000000',
        ],
    ];

    public const USDC_CODE = 'USD';

    public const USDC_SETTINGS = [
        'mainnet' => [
            'issuer' => 'rGm7WCVp9gb4jZHWTEtGUr4dd74z2XuWhE',
            'currency' => '5553444300000000000000000000000000000000',
        ],
        'testnet' => [
            'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
            'currency' => '5553444300000000000000000000000000000000',
        ],
    ];

    /**
     * Build an issued-currency amount object for RLUSD on XRPL.
     *
     * @param string $network e.g. 'mainnet' or 'testnet'
     * @param string $value Decimal string with 2 fraction digits, e.g. "12.34"
     * @return array{currency:string, value:string, issuer:string}
     */
    public function getRLUSDAmount(string $network, string $value): array
    {
        return [
            'currency' => self::RLUSD_SETTINGS[$network]['currency'],
            'value' => $value,
            'issuer' => self::RLUSD_SETTINGS[$network]['issuer'],
        ];
    }

    /**
     * Build an issued-currency amount object for USDC on XRPL.
     *
     * @param string $network e .g. 'mainnet' or 'testnet'
     * @param string $value Decimal string with 2 fraction digits
     * @return array{currency:string, value:string, issuer:string}
     */
    public function getUSDCAmount(string $network, string $value): array
    {
        return [
            'currency' => self::USDC_SETTINGS[$network]['currency'],
            'value' => $value,
            'issuer' => self::USDC_SETTINGS[$network]['issuer'],
        ];
    }
}
