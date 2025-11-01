<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

use Hardcastle\LedgerDirect\Service\ConfigurationService;

class StablecoinProvider
{
    public const RLUSD_CODE = 'RLUSD';

    public function __construct(private readonly ConfigurationService $configurationService)
    {
    }

    /**
     * Build an issued-currency amount object for RLUSD.
     *
     * @param string|null $network e.g. 'mainnet' or 'testnet' (optional, currently resolved from configuration)
     * @param string $value Decimal string with 2 fraction digits, e.g. "12.34"
     * @return array{currency:string, value:string, issuer:string}
     */
    public function getRLUSDAmount(?string $network, string $value): array
    {
        // Issuer can be stored in Shopware config (different per network via ConfigurationService)
        $issuer = $this->configurationService->getIssuer();

        return [
            'currency' => self::RLUSD_CODE,
            'value' => $value,
            'issuer' => $issuer,
        ];
    }
}
