<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use PDO;
use Shopware\Core\Framework\Uuid\Uuid;

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

    /**
     * Generate a unique destination tag
     *
     * @return int
     * @throws Exception|DriverException
     */
    public function generateDestinationTag(): int
    {
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

    /**
     * Finds a XRPL transaction based on the given destination address and destination tag.
     *
     * @param string $destination The destination address to search for.
     * @param int $destinationTag The destination tag associated with the transaction.
     *
     * @return array|null Returns the matching transaction as an associative array if found, or null if no match is found.
     * @throws DriverException
     * @throws \Doctrine\DBAL\Exception
     */
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

        // TODO: If for whatever reason there are more than one matches, add them up.

        return null;
    }

    /**
     * Syncs transactions for a given XRPL address and stores them in the database.
     *
     * @param string $address The XRPL address to sync transactions for.
     * @throws Exception|\GuzzleHttp\Exception\GuzzleException
     */
    public function syncTransactions(string $address): void
    {
        $lastLedgerIndex = (int) $this->connection->fetchOne('SELECT MAX(ledger_index) FROM xrpl_tx');

        if (!$lastLedgerIndex) {
            $lastLedgerIndex = null;
        }

        $marker = null;
        $pages = 0;
        $maxPages = 1000; // safety guard

        do {
            $response = $this->clientService->fetchAccountTransactions($address, $lastLedgerIndex, $marker);
            $transactions = $response['transactions'] ?? [];

            if (!empty($transactions)) {
                $this->txToDb($transactions, $address);
            }

            $marker = $response['marker'] ?? null;
            $pages++;
        } while ($marker !== null && $pages < $maxPages);
    }

    /**
     * Resets the XRPL transactions database table by truncating it.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function resetDatabase(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE xrpl_tx');
    }

    /**
     * Processes and stores XRPL transactions in the database.
     *
     * @param array $transactions The array of XRPL transactions to process.
     * @param string $address The XRPL address to filter incoming transactions for.
     * @throws \Doctrine\DBAL\Exception
     */
    public function txToDb(array $transactions, string $address): void
    {
        $transactions = $this->filterIncomingTransactions($transactions, $address);
        $transactions = $this->filterNewTransactions($transactions);

        $rows = $this->hydrateRows($transactions);

        foreach ($rows as $row) {
            $this->connection->insert('xrpl_tx', $row);
        }
    }


    /**
     * Filters incoming transactions, keeping only those where the destination address matches the given address.
     *
     * @param array $transactions The list of transactions to filter.
     * @param string $ownAddress The address to match as the destination in the transactions.
     * @return array The filtered list of transactions.
     */
    private function filterIncomingTransactions(array $transactions, string $ownAddress): array
    {
        foreach ($transactions as $key => $transaction) {
            if (!isset($transaction['tx']['Destination']) || $transaction['tx']['Destination'] !== $ownAddress) {
                unset($transactions[$key]);
            }
        }

        return $transactions;
    }

    /**
     * Filters an array of transactions to remove those that already exist in the database.
     *
     * @param array $transactions An array of transactions to be filtered.
     * @return array A filtered array containing only new transactions absent from the database.
     * @throws Exception If the database query fails.
     */
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

    /**
     * Hydrates transaction data into a structured array of rows ready for database insertion.
     *
     * @param array $transactions An array of transaction data to process and format.
     * @return array An array of formatted rows containing transaction details.
     * @throws \InvalidArgumentException If the transactions data is improperly formatted or missing required fields.
     */
    private function hydrateRows(array $transactions): array
    {
        $rows = [];

        foreach ($transactions as $key => $transaction) {
            $rows[] = [
                'id' => hex2bin(Uuid::randomHex()),
                'ledger_index' => $transaction['tx']['ledger_index'],
                'hash' => $transaction['tx']['hash'],
                'ctid' => $transaction['tx']['ctid'],
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
}