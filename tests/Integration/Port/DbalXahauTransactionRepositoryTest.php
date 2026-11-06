<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Port;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Port\DbalXrplTransactionRepository;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * Xahau's repository is the XRPL one on Xahau's tables. What matters is that
 * nothing crosses over: the same `r…` address can receive on both chains,
 * and a transaction or a tag counter of one must never show up on the other.
 */
class DbalXahauTransactionRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour; // wraps each test in a rolled-back DB transaction

    /** The same account on both chains — the case the separate tables exist for. */
    private const ACCOUNT = 'r4ERx8577eKBSAT1pLefizNPYCYfhk1op2';

    private Connection $connection;

    private DbalXrplTransactionRepository $xrpl;

    private DbalXrplTransactionRepository $xahau;

    protected function setUp(): void
    {
        $this->connection = $this->getContainer()->get(Connection::class);
        $this->xrpl = new DbalXrplTransactionRepository($this->connection);
        $this->xahau = new DbalXrplTransactionRepository($this->connection, 'XAHAU');

        foreach (['ledger_direct_xrpl_tx', 'ledger_direct_xrpl_destination_tag', 'ledger_direct_xahau_tx', 'ledger_direct_xahau_destination_tag'] as $table) {
            $this->connection->executeStatement('DELETE FROM `' . $table . '`');
        }
    }

    public function testTheMigrationCreatedXahausTablesWithTheXrplColumns(): void
    {
        $columns = fn (string $table): array => array_column(
            $this->connection->fetchAllAssociative('SHOW COLUMNS FROM `' . $table . '`'),
            'Type',
            'Field'
        );

        $this->assertSame($columns('ledger_direct_xrpl_tx'), $columns('ledger_direct_xahau_tx'));
        $this->assertSame($columns('ledger_direct_xrpl_destination_tag'), $columns('ledger_direct_xahau_destination_tag'));
    }

    public function testATransactionIsStoredOnItsOwnChainOnly(): void
    {
        $this->xahau->saveTransactions([$this->transaction('HASH_ON_XAHAU', 'C0D316CA0004535A')]);

        $this->assertCount(1, $this->xahau->findTransactions(self::ACCOUNT, 1000001));
        $this->assertSame([], $this->xrpl->findTransactions(self::ACCOUNT, 1000001), 'the XRP Ledger does not see it');
        $this->assertSame('13833930', $this->xahau->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'));
        $this->assertNull($this->xrpl->getLastSyncedLedgerIndex(self::ACCOUNT, 'testnet'), 'and its cursor does not move');
        $this->assertSame(['HASH_ON_XAHAU'], $this->xahau->findExistingHashes(['HASH_ON_XAHAU', 'HASH_UNKNOWN']));
        $this->assertSame([], $this->xrpl->findExistingHashes(['HASH_ON_XAHAU']));
    }

    public function testTheTagCountersOfTheTwoChainsAreIndependent(): void
    {
        $xahauFirst = $this->xahau->nextDestinationTagSequence(self::ACCOUNT);
        $xrplFirst = $this->xrpl->nextDestinationTagSequence(self::ACCOUNT);

        $this->assertSame($xahauFirst + 1, $this->xahau->nextDestinationTagSequence(self::ACCOUNT));
        $this->assertSame($xrplFirst + 1, $this->xrpl->nextDestinationTagSequence(self::ACCOUNT));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `ledger_direct_xahau_destination_tag`'));
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `ledger_direct_xrpl_destination_tag`'));
    }

    private function transaction(string $hash, string $ctid): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet',
            ledgerIndex: '13833930',
            hash: $hash,
            ctid: $ctid,
            account: 'rDF9bEuYAmkEfeQgf7C64BsJWJoWqS5NaV',
            destination: self::ACCOUNT,
            destinationTag: 1000001,
            date: 0,
            meta: ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => '1500000'],
            tx: ['TransactionType' => 'Payment'],
        );
    }
}
