<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

use Exception;
use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\Oracle\CoingeckoOracle;

class UsdcPriceProvider implements CryptoPriceProviderInterface
{
    public const CRYPTO_CODE = 'USDC';

    public const DEFAULT_ALLOWED_DIVERGENCE = 0.05;

    public const USDC_ROUND_PLACES = 2;

    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Gets the current USDC price by averaging multiple oracles
     *
     * @param string $code Fiat ISO code (e.g. 'EUR', 'USD')
     * @param bool|null $round Round to 2 decimals if true
     * @return float|false
     */
    public function getCurrentExchangeRate(string $code, ?bool $round = false): float|false
    {
        // If the code is USD, return 1 as USDC is (intended to be) pegged to USD
        if ($code === 'USD') {
            return 1;
        }

        $oracleResults = [];
        $filteredPrices = [];

        $oracles = [
            new CoingeckoOracle()
        ];

        foreach ($oracles as $oracle) {
            try {
                $price = $oracle->prepare($this->client)->getCurrentPriceForPair(self::CRYPTO_CODE, $code);
                if ($price > 0.0) {
                    $oracleResults[] = $price;
                }
            } catch (Exception $exception) {
                // TODO: Log error
            }
        }

        if (count($oracleResults) === 0) {
            return false;
        }

        $avg = array_sum($oracleResults) / count($oracleResults);
        foreach ($oracleResults as $price) {
            if (abs($avg - $price) < $avg * self::DEFAULT_ALLOWED_DIVERGENCE) {
                $filteredPrices[] = $price;
            }
        }

        if (count($filteredPrices) > 0) {
            $avg = array_sum($filteredPrices) / count($filteredPrices);
            return $round ? $this->roundPrice($avg) : $avg;
        }

        return false;
    }

    public function checkPricePlausibility(float $price): bool
    {
        return $price > 0.0;
    }

    private function roundPrice(float $price): float
    {
        return round($price, self::USDC_ROUND_PLACES);
    }
}
