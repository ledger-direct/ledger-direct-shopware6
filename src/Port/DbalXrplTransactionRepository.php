<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Platform side of {@see XrplTransactionRepositoryInterface}, on Shopware's
 * DBAL connection. Storage primitives only — the sync, dedup and tag
 * derivation around them live in the core.
 */
class DbalXrplTransactionRepository implements XrplTransactionRepositoryInterface
{
    private const TX_TABLE = 'ledger_direct_xrpl_tx';

    private const TAG_TABLE = 'ledger_direct_xrpl_destination_tag';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * One atomic statement, not a select-then-update: two checkouts hitting
     * the same account concurrently must never receive the same sequence
     * number, or two orders end up sharing a destination tag and the second
     * payment settles against the first order.
     *
     * MySQL's LAST_INSERT_ID(expr) is what makes that possible in a single
     * round trip — it both stores and returns the new counter value, per
     * connection, so the value read back is this caller's own.
     *
     * The counter is 1-based (a fresh row starts at 1) while the port
     * contract is 0-based, hence the -1.
     */
    public function nextDestinationTagSequence(string $destinationAccount): int
    {
        $this->connection->executeStatement(
            'INSERT INTO `' . self::TAG_TABLE . '` (`destination_account`, `sequence`)
             VALUES (:destination_account, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE `sequence` = LAST_INSERT_ID(`sequence` + 1)',
            ['destination_account' => $destinationAccount],
            ['destination_account' => ParameterType::STRING]
        );

        return ((int) $this->connection->fetchOne('SELECT LAST_INSERT_ID()')) - 1;
    }

    /**
     * @param string[] $hashes
     * @return string[]
     */
    public function findExistingHashes(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $matches = $this->connection->fetchFirstColumn(
            'SELECT `hash` FROM `' . self::TX_TABLE . '` WHERE `hash` IN (:hashes)',
            ['hashes' => array_values($hashes)],
            ['hashes' => ArrayParameterType::STRING]
        );

        return array_map('strval', $matches);
    }

    /**
     * @param XrplTransaction[] $transactions
     */
    public function saveTransactions(array $transactions): void
    {
        foreach ($transactions as $transaction) {
            try {
                $this->connection->insert(self::TX_TABLE, [
                    'id' => Uuid::randomBytes(),
                    'ledger_index' => $transaction->ledgerIndex,
                    'hash' => $transaction->hash,
                    'ctid' => $transaction->ctid,
                    'account' => $transaction->account,
                    'destination' => $transaction->destination,
                    'destination_tag' => $transaction->destinationTag,
                    'date' => $transaction->date,
                    'meta' => json_encode($transaction->meta, JSON_THROW_ON_ERROR),
                    'tx' => json_encode($transaction->tx, JSON_THROW_ON_ERROR),
                ]);
            } catch (UniqueConstraintViolationException) {
                /*
                 * The unique index on `hash` doing its job: a concurrent sync
                 * stored this transaction between the core's dedup check and
                 * this insert. Nothing to do — the row is already there.
                 */
            }
        }
    }

    public function findTransaction(string $destination, int $destinationTag): ?XrplTransaction
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `' . self::TX_TABLE . '`
             WHERE `destination` = :destination AND `destination_tag` = :destination_tag',
            ['destination' => $destination, 'destination_tag' => $destinationTag],
            ['destination' => ParameterType::STRING, 'destination_tag' => ParameterType::INTEGER]
        );

        return $row === false ? null : self::hydrate($row);
    }

    public function getLastSyncedLedgerIndex(): ?string
    {
        $lastSyncedLedgerIndex = $this->connection->fetchOne(
            'SELECT MAX(`ledger_index`) FROM `' . self::TX_TABLE . '`'
        );

        return $lastSyncedLedgerIndex === null || $lastSyncedLedgerIndex === false
            ? null
            : (string) $lastSyncedLedgerIndex;
    }

    public function truncate(): void
    {
        $this->connection->executeStatement('TRUNCATE TABLE `' . self::TX_TABLE . '`');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: (string) $row['ledger_index'],
            hash: (string) $row['hash'],
            ctid: (string) $row['ctid'],
            account: (string) $row['account'],
            destination: (string) $row['destination'],
            destinationTag: $row['destination_tag'] === null ? null : (int) $row['destination_tag'],
            date: (int) $row['date'],
            meta: self::decodeJsonColumn($row['meta'] ?? null),
            tx: self::decodeJsonColumn($row['tx'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonColumn(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
