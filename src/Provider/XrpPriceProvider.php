<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

use Exception;
use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\Oracle\BinanceOracle;
use Hardcastle\LedgerDirect\Provider\Oracle\KrakenOracle;
use Hardcastle\LedgerDirect\Provider\Oracle\RippleOracle;

class XrpPriceProvider implements CryptoPriceProviderInterface
{
    public const CRYPTO_CODE = 'XRP';

    public const DEFAULT_ALLOWED_DIVERGENCE = 0.05;

    public const XRP_ROUND_PLACES = 5;

    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Gets the current XRP price by querying averaging multiple oracles
     *
     * @param string $code
     * @return float|false
     */
    public function getCurrentExchangeRate(string $code): float|false
    {
        $oracleResults = [];
        $filteredPrices = [];

        $oracles = [
            new BinanceOracle(),
            new KrakenOracle(),
            // new RippleOracle(), // Disabled due to issues with the Ripple API
        ];

        foreach ($oracles as $oracle) {
            try {

                $price = $oracle->prepare($this->client)->getCurrentPriceForPair(self::CRYPTO_CODE, $code);

                if ($price > 0.0) {
                    $oracleResults[] = $price;
                }
            } catch (Exception $exception) {
                echo $exception->getMessage();
            }
        }

        if (count($oracleResults) === 0) {
            return false;
        }

        $avg = array_sum($oracleResults) / count($oracleResults);
        foreach ($oracleResults as $price) {
            if (abs($avg-$price) < $avg * self::DEFAULT_ALLOWED_DIVERGENCE) {
                $filteredPrices[] = $price;
            }
        }

        if(count($filteredPrices) > 0) {
            $price = array_sum($filteredPrices) / count($filteredPrices);
            return round($price, self::XRP_ROUND_PLACES);
        }

        return false;
    }

    /**
     *
     *
     * @param float $price
     * @return bool
     */
    public function checkPricePlausibility(float $price): bool
    {
        if ($price > 0.0) {
            return true;
        }

        return false ;
    }
}