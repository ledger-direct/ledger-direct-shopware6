<?php declare(strict_types=1);

namespace LedgerDirect\Service;

use DateTime;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use XRPL_PHP\Client\JsonRpcClient;
use XRPL_PHP\Core\Networks;
use XRPL_PHP\Models\Account\AccountTxRequest;
use LedgerDirect\Entity\XrplTxEntity;

class XrplClientService
{
    private ConfigurationService $configurationService;

    private JsonRpcClient $client;

    public function __construct(ConfigurationService $configurationService) {
        $this->configurationService = $configurationService;

        $this->_initClient();
    }

    public function fetchAccountTransactions(string $address, ?int $lastLedgerIndex): array
    {
        $req = new AccountTxRequest($address, $lastLedgerIndex);
        $res = $this->client->syncRequest($req);

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

    private function _initClient()
    {
        $jsonRpcUrl = $this->getNetwork()['jsonRpcUrl'];
        $this->client = new JsonRpcClient($jsonRpcUrl);
    }
}