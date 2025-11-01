<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class XrplClientService
{
    private ConfigurationService $configurationService;

    private ClientInterface $httpClient;

    public function __construct(
        ConfigurationService $configurationService,
        ClientInterface $httpClient
    ) {
        $this->configurationService = $configurationService;
        $this->httpClient = $httpClient;
    }

    /**
     * Fetches account transactions for a given address from the XRPL network using JSON-RPC.
     *
     * @param string $address
     * @param int|null $lastLedgerIndex
     * @return array
     * @throws GuzzleException
     */
    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex): array
    {
        $body = [
            'jsonrpc' => '2.0',
            'method' => 'account_tx',
            'params' => [[
                'account' => $address,
                // fetch forward=false (default) newest-first; use ledger_index_min to paginate if provided
                'ledger_index_min' => $lastLedgerIndex ? $lastLedgerIndex + 1 : -1,
                'limit' => 200,
            ]],
            'id' => 1,
        ];

        $response = $this->httpClient->request('POST', $this->getNetwork()['jsonRpcUrl'], [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'http_errors' => false,
            'timeout' => 15,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return [];
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            return [];
        }

        // XRPL JSON-RPC may either return {result: {...}} or top-level 'error'
        if (isset($payload['error']) || (isset($payload['result']['status']) && $payload['result']['status'] === 'error')) {
            return [];
        }

        return $payload['result']['transactions'] ?? [];
    }

    /**
     * Returns network configuration including jsonRpcUrl.
     *
     * @return array{network: string, jsonRpcUrl: string}
     */
    public function getNetwork(): array
    {
        $isTest = $this->configurationService->isTest();
        if ($isTest) {
            return [
                'network' => 'testnet',
                // Public XRPL Testnet JSON-RPC endpoint
                'jsonRpcUrl' => 'https://s.altnet.rippletest.net:51234/',
            ];
        }

        return [
            'network' => 'mainnet',
            // Public XRPL Mainnet JSON-RPC endpoint (cluster)
            'jsonRpcUrl' => 'https://xrplcluster.com/',
        ];
    }
}