<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Hardcastle\LedgerDirect\Interledger\Port\InterledgerRepositoryInterface;
use Hardcastle\LedgerDirect\Interledger\Schema;

/**
 * Platform side of {@see InterledgerRepositoryInterface}: the reference
 * counter per wallet address, in `ledger_direct_interledger_reference`.
 *
 * One method, because that is the whole port — Open Payments keeps the
 * payments at the merchant's wallet, so there is no transaction table to
 * mirror them into. The counter itself is the XRPL's and Stellar's, down to
 * the SQL: one atomic statement, a random start per address, read back
 * through `LAST_INSERT_ID()`.
 *
 * Constructed by InterledgerGateway rather than declared as a service: the
 * interface it implements comes from an optional package, so this class must
 * not be loaded in an installation that does not have it.
 */
class DbalInterledgerRepository implements InterledgerRepositoryInterface
{
    private const TABLE = Schema::TABLE_REFERENCE;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The next sequence number for a wallet address.
     *
     * One statement, no transaction, no read-modify-write: `INSERT … ON
     * DUPLICATE KEY UPDATE` with `LAST_INSERT_ID()` makes the increment
     * atomic under concurrent checkouts, and the first call for an address
     * stores a random start — two shops on one wallet address must not walk
     * the same sequence and hand out each other's references.
     */
    public function nextReferenceSequence(string $walletAddress): int
    {
        $this->connection->executeStatement(
            'INSERT INTO `' . self::TABLE . '` (`wallet_address`, `sequence`)
             VALUES (:wallet_address, LAST_INSERT_ID(:start))
             ON DUPLICATE KEY UPDATE `sequence` = LAST_INSERT_ID(`sequence` + 1)',
            [
                'wallet_address' => $walletAddress,
                'start' => random_int(1, 2147483648),
            ],
            ['wallet_address' => ParameterType::STRING, 'start' => ParameterType::INTEGER]
        );

        return ((int) $this->connection->fetchOne('SELECT LAST_INSERT_ID()')) - 1;
    }
}
