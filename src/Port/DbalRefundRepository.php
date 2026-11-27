<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundSchema;

/**
 * Platform side of {@see RefundRepositoryInterface}, on Shopware's DBAL
 * connection. Storage primitives only: which refund may exist, what executes
 * it and what a payment on the ledger means is decided in the core.
 *
 * One instance per chain that speaks the XRPL protocol, like
 * DbalXrplTransactionRepository: `ledger_direct_xrpl_refund` by default,
 * `ledger_direct_xahau_refund` for `XAHAU` (Xrpl\RefundSchema). A row does
 * not say which chain it is from, the table does, so a refund of another
 * chain is refused before anything is written.
 *
 * Rows are Refund::toRow() and are read with Refund::fromRow(), which reads
 * strictly: a row that is not a refund is an exception, not a guess.
 *
 * Four rules hold only as the table's unique indexes, because two requests
 * can arrive at the same moment: the numbering per incoming payment, the
 * open key, the execution hash, the reference. insert() and update() report
 * a refusal by one of them as false; there is no query-then-write here.
 *
 * **The table is a record, not a cache.** There is deliberately no
 * truncate() and no delete: what a row holds cannot be synced back from the
 * ledger, and with the row gone the payment it refunded could be refunded in
 * full again.
 */
class DbalRefundRepository implements RefundRepositoryInterface
{
    /** Still looked after: awaiting execution, or closed at or after the given time. */
    private const WATCHED = '(`status` = :awaiting OR `closed_at` >= :closed_since)';

    private Connection $connection;

    private string $chain;

    private string $table;

    /** The column list of every read, quoted: exactly what Refund::fromRow() asks for. */
    private string $columns;

    public function __construct(Connection $connection, string $chain = ChainCatalog::XRPL)
    {
        $this->connection = $connection;
        $this->chain = $chain;
        // Refuses a chain that has no refund table.
        $this->table = (string) array_key_first(RefundSchema::tables($chain));
        $this->columns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', Refund::COLUMNS));
    }

    public function insert(Refund $refund): bool
    {
        $row = $this->row($refund);

        $names = array_map(static fn (string $column): string => '`' . $column . '`', array_keys($row));
        $placeholders = array_map(static fn (string $column): string => ':' . $column, array_keys($row));

        try {
            $this->connection->executeStatement(
                'INSERT INTO `' . $this->table . '` (' . implode(', ', $names) . ') VALUES (' . implode(', ', $placeholders) . ')',
                $row,
                self::types($row)
            );
        } catch (UniqueConstraintViolationException) {
            // The primary key or one of the four unique indexes: nothing is stored.
            return false;
        }

        return true;
    }

    /**
     * One statement that checks the version and writes: a second writer that
     * read the same version changes no row. The new row always differs from
     * the stored one, in `version` at least, so "no row changed" means the
     * version had moved on or the refund does not exist.
     */
    public function update(Refund $refund, int $expectedVersion): bool
    {
        $row = $this->row($refund);

        $assignments = [];
        foreach (array_keys($row) as $column) {
            if ($column !== 'id') {
                $assignments[] = '`' . $column . '` = :' . $column;
            }
        }

        $parameters = $row + ['expected_version' => $expectedVersion];

        try {
            $changed = $this->connection->executeStatement(
                'UPDATE `' . $this->table . '` SET ' . implode(', ', $assignments)
                . ' WHERE `id` = :id AND `version` = :expected_version',
                $parameters,
                self::types($parameters)
            );
        } catch (UniqueConstraintViolationException) {
            // A unique index refused the change, e.g. a payment that already executes another refund.
            return false;
        }

        return $changed === 1;
    }

    public function find(string $id): ?Refund
    {
        return $this->select('`id` = :id', ['id' => $id], '`id` ASC')[0] ?? null;
    }

    public function findByReference(string $network, string $reference): ?Refund
    {
        return $this->select(
            '`network` = :network AND `reference` = :reference',
            ['network' => $network, 'reference' => $reference],
            '`id` ASC'
        )[0] ?? null;
    }

    public function findBySource(string $network, string $sourceHash): array
    {
        return $this->select(
            '`network` = :network AND `source_hash` = :source_hash',
            ['network' => $network, 'source_hash' => $sourceHash],
            '`serial` ASC'
        );
    }

    public function findByPlatformReference(string $platformReference): array
    {
        return $this->select('`platform_reference` = :platform_reference', ['platform_reference' => $platformReference]);
    }

    public function findByDestination(string $network, string $payingAccount, string $destination): array
    {
        return $this->select(
            '`network` = :network AND `paying_account` = :paying_account AND `destination` = :destination',
            ['network' => $network, 'paying_account' => $payingAccount, 'destination' => $destination]
        );
    }

    public function findWatched(string $network, string $payingAccount, int $closedSince): array
    {
        return $this->select(
            '`network` = :network AND `paying_account` = :paying_account AND ' . self::WATCHED,
            [
                'network' => $network,
                'paying_account' => $payingAccount,
                'awaiting' => Refund::STATUS_AWAITING_EXECUTION,
                'closed_since' => $closedSince,
            ]
        );
    }

    public function watchedAccounts(string $network, int $closedSince): array
    {
        $parameters = ['network' => $network, 'awaiting' => Refund::STATUS_AWAITING_EXECUTION, 'closed_since' => $closedSince];

        $accounts = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT `paying_account` FROM `' . $this->table . '`
             WHERE `network` = :network AND ' . self::WATCHED . '
             ORDER BY `paying_account` ASC',
            $parameters,
            self::types($parameters)
        );

        return array_map('strval', $accounts);
    }

    /**
     * Oldest first unless told otherwise, with the id as the tie-break so the
     * order is total.
     *
     * @param array<string, string|int> $parameters
     * @return list<Refund>
     */
    private function select(string $where, array $parameters, string $orderBy = '`created_at` ASC, `id` ASC'): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . $this->columns . ' FROM `' . $this->table . '` WHERE ' . $where . ' ORDER BY ' . $orderBy,
            $parameters,
            self::types($parameters)
        );

        return array_map(fn (array $row): Refund => Refund::fromRow($row, $this->chain), $rows);
    }

    /**
     * @return array<string, string|int|null>
     */
    private function row(Refund $refund): array
    {
        if ($refund->chain !== $this->chain) {
            throw new \InvalidArgumentException(sprintf(
                'This repository stores %s refunds (%s); the refund is on %s.',
                $this->chain,
                $this->table,
                $refund->chain
            ));
        }

        return $refund->toRow();
    }

    /**
     * Integers are bound as integers and null as null, never as text the
     * database has to convert back. No return type on the closure: the
     * parameter types are an enum in DBAL 4 (Shopware 6.7) and integer
     * constants in DBAL 3 (Shopware 6.6).
     *
     * @param array<string, string|int|null> $parameters
     * @return array<string, ParameterType|int>
     */
    private static function types(array $parameters): array
    {
        return array_map(
            static fn (string|int|null $value) => match (true) {
                $value === null => ParameterType::NULL,
                is_int($value) => ParameterType::INTEGER,
                default => ParameterType::STRING,
            },
            $parameters
        );
    }
}
