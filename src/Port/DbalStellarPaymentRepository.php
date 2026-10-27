<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Hardcastle\LedgerDirect\Core\Port\StellarPaymentRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Stellar\Schema;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;

/**
 * Platform side of {@see StellarPaymentRepositoryInterface}, on Shopware's
 * DBAL connection — the sibling of DbalXrplTransactionRepository. Storage
 * primitives only; sync, dedup and memo-id derivation live in the core.
 *
 * Identifiers are strings end to end (core rule E9): `memo_id` and
 * `payment_id` are bound and read as strings, never cast to int — a foreign
 * memo can use the full unsigned 64-bit range PHP's int does not have.
 */
class DbalStellarPaymentRepository implements StellarPaymentRepositoryInterface
{
    private const PAYMENT_TABLE = Schema::TABLE_PAYMENT;

    private const MEMO_TABLE = Schema::TABLE_MEMO;

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * The same atomic counter as the XRPL destination-tag sequence, for the
     * same reasons (see DbalXrplTransactionRepository::nextDestinationTagSequence()
     * and the port): one statement, a random start, 0-based towards the core.
     */
    public function nextMemoIdSequence(string $destinationAccount): int
    {
        $this->connection->executeStatement(
            'INSERT INTO `' . self::MEMO_TABLE . '` (`destination_account`, `sequence`)
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
     * @param list<string> $keys "{hash}:{op_index}", as StellarPayment::key() builds them
     * @return list<string>
     */
    public function findExistingKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $pairs = [];
        $parameters = [];

        foreach ($keys as $i => $key) {
            [$hash, $opIndex] = array_pad(explode(':', $key, 2), 2, '0');
            $pairs[] = "(`hash` = :hash{$i} AND `op_index` = :op{$i})";
            $parameters["hash{$i}"] = $hash;
            $parameters["op{$i}"] = (int) $opIndex;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT `hash`, `op_index` FROM `' . self::PAYMENT_TABLE . '` WHERE ' . implode(' OR ', $pairs),
            $parameters
        );

        return array_map(static fn (array $row): string => $row['hash'] . ':' . $row['op_index'], $rows);
    }

    /**
     * @param list<StellarPayment> $payments
     */
    public function savePayments(array $payments): void
    {
        foreach ($payments as $payment) {
            try {
                $this->connection->insert(self::PAYMENT_TABLE, [
                    'network' => $payment->network,
                    'payment_id' => $payment->paymentId,
                    'hash' => $payment->hash,
                    'op_index' => $payment->opIndex,
                    'type' => $payment->type,
                    'account' => $payment->account,
                    'destination' => $payment->destination,
                    'memo_id' => $payment->memoId,
                    'asset_code' => $payment->assetCode,
                    'asset_issuer' => $payment->assetIssuer,
                    'amount' => $payment->amount,
                    'created_at' => $payment->createdAt,
                    'raw' => json_encode($payment->raw, JSON_THROW_ON_ERROR),
                ], [
                    'payment_id' => ParameterType::STRING,
                    'memo_id' => ParameterType::STRING,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The unique index on (hash, op_index) doing its job: a concurrent
                // sync stored this operation between the core's dedup check and
                // this insert. The row is there, which is the point.
            }
        }
    }

    /**
     * Newest first: `payment_id` is the TOID, chronological within a network;
     * the auto-increment id breaks a tie by storage order so the order is
     * total — the core's wrong-asset choice depends on "newest".
     *
     * @return list<StellarPayment>
     */
    public function findPayments(string $destination, string $memoId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `' . self::PAYMENT_TABLE . '`
             WHERE `destination` = :destination AND `memo_id` = :memo_id
             ORDER BY `payment_id` DESC, `id` DESC',
            ['destination' => $destination, 'memo_id' => $memoId],
            ['destination' => ParameterType::STRING, 'memo_id' => ParameterType::STRING]
        );

        return array_map([self::class, 'hydrate'], $rows);
    }

    public function getLastPaymentId(string $destinationAccount, string $network): ?string
    {
        $last = $this->connection->fetchOne(
            'SELECT MAX(`payment_id`) FROM `' . self::PAYMENT_TABLE . '`
             WHERE `destination` = :destination AND `network` = :network',
            ['destination' => $destinationAccount, 'network' => $network],
            ['destination' => ParameterType::STRING, 'network' => ParameterType::STRING]
        );

        return $last === null || $last === false ? null : (string) $last;
    }

    /**
     * DELETE rather than TRUNCATE: the port wants the table emptied, and
     * TRUNCATE commits implicitly on MySQL, which breaks the rolled-back
     * transaction the integration suite (and the core's contract test) runs
     * in. The counter table is not touched - a memo id must never be handed
     * out twice, whatever happens to the cache of synced payments.
     */
    public function truncate(): void
    {
        $this->connection->executeStatement('DELETE FROM `' . self::PAYMENT_TABLE . '`');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): StellarPayment
    {
        $raw = is_string($row['raw'] ?? null) ? json_decode($row['raw'], true) : null;

        return new StellarPayment(
            network: (string) $row['network'],
            paymentId: (string) $row['payment_id'],
            hash: (string) $row['hash'],
            opIndex: (int) $row['op_index'],
            type: (string) $row['type'],
            account: (string) $row['account'],
            destination: (string) $row['destination'],
            memoId: $row['memo_id'] === null ? null : (string) $row['memo_id'],
            assetCode: (string) $row['asset_code'],
            assetIssuer: $row['asset_issuer'] === null ? null : (string) $row['asset_issuer'],
            amount: (string) $row['amount'],
            createdAt: (int) $row['created_at'],
            raw: is_array($raw) ? $raw : [],
        );
    }
}
