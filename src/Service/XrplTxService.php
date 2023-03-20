<?php declare(strict_types=1);

namespace LedgerDirect\Service;

use DateTime;
use Doctrine\DBAL\Connection;
use PDO;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use XRPL_PHP\Client\JsonRpcClient;
use XRPL_PHP\Models\Account\AccountTxRequest;
use LedgerDirect\Entity\XrplTxEntity;

class XrplTxService
{
    public const DESTINATION_TAG_RANGE_MIN = 10000;

    public const DESTINATION_TAG_RANGE_MAX = 2140000000;

    protected XrplClientService $clientService;

    private Connection $connection;

    public function __construct(
        XrplClientService $clientService,
        Connection $connection
    ) {
        $this->clientService = $clientService;
        $this->connection = $connection;
    }

    public function generateDestinationTag(): int
    {
        // https://xrpl.org/source-and-destination-tags.html
        // https://xrpl.org/require-destination-tags.html

        while (true) {
            $destinationTag = random_int(self::DESTINATION_TAG_RANGE_MIN, self::DESTINATION_TAG_RANGE_MAX);

            $statement = $this->connection->executeQuery(
                'SELECT destination_tag FROM xrpl_destination_tag WHERE destination_tag = :destination_tag',
                ['destination_tag' => $destinationTag],
                ['destination_tag' => PDO::PARAM_INT]
            );
            $matches = $statement->fetchAllAssociative();

            if (empty($matches)) {
                $this->connection->insert('xrpl_destination_tag', ['destination_tag' => $destinationTag]);

                return $destinationTag;
            }
        }
    }

    public function findTransaction(string $destination, int $destinationTag): ?array
    {
        $statement = $this->connection->executeQuery(
            'SELECT * FROM xrpl_tx WHERE destination = :destination AND destination_tag = :destination_tag',
            ['destination' => $destination, 'destination_tag' => $destinationTag],
            ['destination' => PDO::PARAM_STR, 'destination_tag' => PDO::PARAM_INT]
        );
        $matches = $statement->fetchAllAssociative();

        if (!empty($matches)) {
            return $matches[0];
        }

        // TODO: If for whatever reason there are more than one matches, throw error

        return null;
    }

    public function syncTransactions(string $address): void
    {
        // TODO: Use only last ledger index

        $lastLedgerIndex = (int) $this->connection->fetchOne('SELECT MAX(ledger_index) FROM xrpl_tx');

        if (!$lastLedgerIndex) {
            $lastLedgerIndex = null;
        }

        $transactions = $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex);

        if (count($transactions)) {
            $this->txToDb($transactions, $address);
        }

        // TODO: If marker is present, loop
    }

    public function resetDatabase(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE xrpl_tx');
    }

    public function txToDb(array $transactions, string $address): void
    {
        $transactions = $this->filterIncomingTransactions($transactions, $address);
        $transactions = $this->filterNewTransactions($transactions);

        $rows = $this->hydrateRows($transactions);

        foreach ($rows as $row) {
            $this->connection->insert('xrpl_tx', $row);
        }
    }


    private function filterIncomingTransactions(array $transactions, string $ownAddress): array
    {
        foreach ($transactions as $key => $transaction) {
            if ($transaction['tx']['Destination'] !== $ownAddress) {
                unset($transactions[$key]);
            }
        }

        return $transactions;
    }

    private function filterNewTransactions(array $transactions): array
    {
        $reducerFn = function ($hashes, $transaction) {
            $hashes[] = $transaction['tx']['hash'];

            return $hashes;
        };
        $hashes = array_reduce($transactions, $reducerFn, []);

        $statement = $this->connection->executeQuery(
            'SELECT hash FROM xrpl_tx WHERE hash IN (:hashes)',
            ['hashes' => $hashes],
            ['hashes' => Connection::PARAM_STR_ARRAY]
        );
        $matches = $statement->fetchAll();

        $lookup = [];
        foreach ($matches as $match) {
            $lookup[] = $match['hash'];
        }

        foreach ($transactions as $key => $transaction) {
            if (in_array($transaction['tx']['hash'], $lookup, true)) {
                unset($transactions[$key]);
            }
        }

        return $transactions;
    }

    private function hydrateRows(array $transactions): array
    {
        $rows = [];

        foreach ($transactions as $key => $transaction) {

            $ledgerIndex = (int) $transaction['tx']['ledger_index'];
            $transactionIndex = (int) $transaction['meta']['TransactionIndex'];
            // https://xrpl.org/connect-your-rippled-to-the-xrp-test-net.html#1-configure-your-server-to-connect-to-the-right-hub
            $networkId = 1; // TODO: Get a proper one

            $rows[] = [
                'id' => hex2bin(Uuid::randomHex()),
                'ledger_index' => $transaction['tx']['ledger_index'],
                'hash' => $transaction['tx']['hash'],
                'ctid' => $this->generateCtid($ledgerIndex, $transactionIndex, $networkId),
                'account' => $transaction['tx']['Account'],
                'destination' => $transaction['tx']['Destination'],
                'destination_tag' => $transaction['tx']['DestinationTag'] ?? null,
                'date' => $transaction['tx']['date'],
                'meta' => json_encode($transaction['meta']),
                'tx' => json_encode($transaction['tx'])
            ];
        }

        return $rows;
    }

    private function generateCtid(int $ledgerIndex, int $transactionIndex, int $networkId): string
    {
        // TODO: Build a proper function in XRPL_PHP, currently it's cargo-culting
        // https://github.com/XRPLF/XRPL-Standards/discussions/91
        $num = ((0xc0000000 + $ledgerIndex) << 32) + ($transactionIndex << 16) + $networkId;

        return strtoupper(dechex($num));
    }

}