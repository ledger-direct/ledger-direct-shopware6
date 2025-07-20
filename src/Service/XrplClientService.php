<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use GuzzleHttp\Exception\GuzzleException;
use Hardcastle\XRPL_PHP\Client\JsonRpcClient;
use Hardcastle\XRPL_PHP\Core\Networks;
use Hardcastle\XRPL_PHP\Models\Account\AccountTxRequest;

class XrplClientService
{
    private ConfigurationService $configurationService;

    private JsonRpcClient $client;

    public function __construct(ConfigurationService $configurationService) {
        $this->configurationService = $configurationService;

        $this->_initClient();
    }

    /**
     * Fetches account transactions for a given address from the XRPL network.
     *
     * @param string $address
     * @param int|null $lastLedgerIndex
     * @return array
     * @throws GuzzleException
     */
    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex): array
    {
        $req = new AccountTxRequest($address, $lastLedgerIndex);
        $res = $this->client->syncRequest($req);

        if ($res->getStatus() === 'error') {
            // LedgerDirect::log('Error fetching account transactions: ' . $res->getError(), 'error');
            return []; // Return an empty array on error
        }

        return $res->getResult()['transactions'];
    }

    public function getNetwork(): array
    {
        if(!$this->configurationService->isTest()) {
            return Networks::getNetwork('mainnet');
        }

        return Networks::getNetwork('testnet');
    }

    /*
     public function getTransaction(string $transactionId): array
    {
        $body = $this->createRequestBody('tx', [
            'transaction' => $transactionId,
            'binary' => false
        ]);

        //TODO: Async
        $res = $this->client->request('POST', $this->endpoint, $body);

        return json_decode((string)$res->getBody(), true);
    }
     */

    private function _initClient(): void
    {
        $jsonRpcUrl = $this->getNetwork()['jsonRpcUrl'];
        $this->client = new JsonRpcClient($jsonRpcUrl);
    }
}