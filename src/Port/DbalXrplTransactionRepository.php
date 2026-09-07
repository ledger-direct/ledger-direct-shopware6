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
     * A fresh counter starts at a random offset rather than at zero, as the
     * port requires: the core derives the tag from this sequence with public
     * constants, so two installations counting from the same start on one
     * receiving account hand out the same tags — and one shop's payment then
     * settles the other shop's order. The upper bound leaves at least ~2.1
     * billion sequences before the core's exhaustion guard trips. See
     * {@see XrplTransactionRepositoryInterface::nextDestinationTagSequence()}
     * for the full reasoning.
     *
     * The counter is 1-based (a fresh row starts at its random draw) while
     * the port contract is 0-based, hence the -1 — and hence the draw runs
     * to 2^31 rather than 2^31-1.
     */
    public function nextDestinationTagSequence(string $destinationAccount): int
    {
        $this->connection->executeStatement(
            'INSERT INTO `' . self::TAG_TABLE . '` (`destination_account`, `sequence`)
             VALUES (:destination_account, LAST_INSERT_ID(:start))
             ON DUPLICATE KEY UPDATE `sequence` = LAST_INSERT_ID(`sequence` + 1)',
            [
                'destination_account' => $destinationAccount,
                'start' => random_int(1, 2147483648),
            ],
            ['destination_account' => ParameterType::STRING, 'start' => ParameterType::INTEGER]
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
                    'network' => $transaction->network,
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

    /**
     * Everything stored on this account/tag pair, newest first — the whole
     * candidate set, filtered by nothing else. Which of them actually pays a
     * given order is the core's decision, not storage's.
     *
     * `id` is a random UUID, so as a tie-breaker it only makes the order
     * total, not chronological; the chronology comes from `ledger_index`.
     *
     * @return XrplTransaction[]
     */
    public function findTransactions(string $destination, int $destinationTag): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `' . self::TX_TABLE . '`
             WHERE `destination` = :destination AND `destination_tag` = :destination_tag
             ORDER BY `ledger_index` DESC, `id` DESC',
            ['destination' => $destination, 'destination_tag' => $destinationTag],
            ['destination' => ParameterType::STRING, 'destination_tag' => ParameterType::INTEGER]
        );

        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * Scoped to the account *and* the network, both load-bearing: a ledger
     * index only means something within one network, so a single mainnet row
     * would otherwise pin the testnet cursor above every testnet ledger and
     * the testnet sync would silently return nothing forever.
     */
    public function getLastSyncedLedgerIndex(string $destinationAccount, string $network): ?string
    {
        $lastSyncedLedgerIndex = $this->connection->fetchOne(
            'SELECT MAX(`ledger_index`) FROM `' . self::TX_TABLE . '`
             WHERE `destination` = :destination AND `network` = :network',
            ['destination' => $destinationAccount, 'network' => $network],
            ['destination' => ParameterType::STRING, 'network' => ParameterType::STRING]
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
            // The fallback covers rows between the ALTER and the backfill.
            network: (string) ($row['network'] ?? ''),
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
