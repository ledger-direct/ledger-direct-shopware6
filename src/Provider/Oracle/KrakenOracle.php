<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider\Oracle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class KrakenOracle implements OracleInterface
{
    private Client $client;

    /**
     * Get the current exchange rate for a currency pair from Kraken.
     *
     * @param string $code1 Currency code for the first currency (e.g., 'XRP').
     * @param string $code2 Currency code for the second currency (e.g., 'USD').
     * @return float Current price of the currency pair.
     * @throws GuzzleException
     */
    public function getCurrentPriceForPair(string $code1, string $code2): float
    {
        $pair = $code1 . $code2;
        $url = 'https://api.kraken.com/0/public/Ticker?pair=' . $pair;

        $response = $this->client->get($url);
        $data = json_decode((string) $response->getBody(), true);

        // Kraken uses a specific format for the pair, e.g., 'XXRPZUSD'
        if (isset($data['result']['XXRPZUSD']['c'])) {
            return (float) $data['result']['XXRPZUSD']['c'][0];
        }

        return 0.0;
    }

    /**
     * Set the HTTP client.
     *
     * @param Client $client
     * @return OracleInterface
     */
    public function prepare(Client $client): OracleInterface
    {
        $this->client = $client;

        return $this;
    }
}