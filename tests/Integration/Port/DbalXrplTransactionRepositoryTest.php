<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Port;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Migration\Migration1788801424NetworkColumn;
use Hardcastle\LedgerDirect\Port\DbalXrplTransactionRepository;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

class DbalXrplTransactionRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour; // wraps each test in a rolled-back DB transaction

    private const DESTINATION_ACCOUNT = 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr';

    private const OTHER_ACCOUNT = 'rSomeOtherMerchantAccount';

    /** XRPL's DestinationTag is unsigned 32-bit; the counter may start anywhere below half of it. */
    private const MAX_SEQUENCE_START = 2147483647;

    private Connection $connection;

    private DbalXrplTransactionRepository $repository;

    protected function setUp(): void
    {
        $this->connection = $this->getContainer()->get(Connection::class);
        $this->repository = new DbalXrplTransactionRepository($this->connection);

        // Both tables are caches/counters, and this runs inside the rolled-back
        // transaction, so starting from empty is safe and keeps assertions exact.
        $this->connection->executeStatement('DELETE FROM `ledger_direct_xrpl_tx`');
        $this->connection->executeStatement('DELETE FROM `ledger_direct_xrpl_destination_tag`');
    }

    /**
     * The port contract: strictly increasing per account, and a fresh counter
     * starts at a random offset rather than at zero.
     */
    public function testDestinationTagSequenceStartsAtARandomOffsetAndIncrements(): void
    {
        $first = $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT);

        $this->assertGreaterThanOrEqual(0, $first);
        $this->assertLessThanOrEqual(self::MAX_SEQUENCE_START, $first);

        $this->assertSame($first + 1, $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT));
        $this->assertSame($first + 2, $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT));
    }

    /**
     * The regression this replaces: every installation used to start at 0, and
     * since the core derives the tag from the sequence with public constants,
     * two shops on one receiving account handed out identical tags — one shop's
     * payment then settled the other's order.
     *
     * Two independent draws colliding is a 1-in-2-billion event; a counter that
     * still starts at a fixed value fails this every time.
     */
    public function testTwoAccountsDoNotStartAtTheSameSequence(): void
    {
        $this->assertNotSame(
            $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT),
            $this->repository->nextDestinationTagSequence(self::OTHER_ACCOUNT)
        );
    }

    public function testEachAccountCountsOnItsOwn(): void
    {
        $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT);
        $advanced = $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT);

        $other = $this->repository->nextDestinationTagSequence(self::OTHER_ACCOUNT);
        $this->assertSame($other + 1, $this->repository->nextDestinationTagSequence(self::OTHER_ACCOUNT));

        // The other account's counter did not disturb this one.
        $this->assertSame($advanced + 1, $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT));
    }

    /**
     * XRPL destination tags are unsigned 32-bit, and the core issues them
     * across that whole range. On a signed column anything above 2147483647
     * was truncated or rejected — and a truncated tag means the customer's
     * payment is never matched to their order.
     */
    public function testATagAboveTheSignedIntegerLimitSurvivesStorage(): void
    {
        $destinationTag = 4294967295;
        $this->assertGreaterThan(2147483647, $destinationTag);

        $this->repository->saveTransactions([$this->givenTransaction('HASH_HIGH_TAG', '90', $destinationTag)]);

        $found = $this->repository->findTransactions(self::DESTINATION_ACCOUNT, $destinationTag);

        $this->assertCount(1, $found);
        $this->assertSame($destinationTag, $found[0]->destinationTag);
        $this->assertSame('HASH_HIGH_TAG', $found[0]->hash);
        $this->assertSame(40.0, $found[0]->getDeliveredAmount());
    }

    /**
     * The tags the core actually generates must fit the column — not just the
     * boundary value picked by hand above.
     */
    public function testGeneratedTagsFitTheColumn(): void
    {
        $destinationTagService = new DestinationTagService($this->repository);

        for ($i = 0; $i < 5; $i++) {
            $destinationTag = $destinationTagService->generateDestinationTag(self::DESTINATION_ACCOUNT);

            $this->repository->saveTransactions([
                $this->givenTransaction('HASH_GENERATED_' . $i, (string) (100 + $i), $destinationTag),
            ]);

            $found = $this->repository->findTransactions(self::DESTINATION_ACCOUNT, $destinationTag);

            $this->assertCount(1, $found, 'tag ' . $destinationTag . ' was not stored as issued');
            $this->assertSame($destinationTag, $found[0]->destinationTag);
        }
    }

    /**
     * A tag can carry more than one transaction, and the port owes the core the
     * full candidate set in a defined order — newest first. Returning whichever
     * row the primary key happened to surface is what let a stray payment
     * settle an order.
     */
    public function testFindTransactionsReturnsEveryCandidateNewestFirst(): void
    {
        $this->repository->saveTransactions([
            $this->givenTransaction('HASH_OLD', '100', 10001),
            $this->givenTransaction('HASH_NEW', '200', 10001),
            $this->givenTransaction('HASH_OTHER_TAG', '300', 10002),
        ]);

        $found = $this->repository->findTransactions(self::DESTINATION_ACCOUNT, 10001);

        $this->assertSame(['HASH_NEW', 'HASH_OLD'], array_map(static fn ($tx) => $tx->hash, $found));
        $this->assertSame([], $this->repository->findTransactions(self::DESTINATION_ACCOUNT, 99999));
    }

    /**
     * A ledger index only means something within one network. A single mainnet
     * row (index ~100 million) would otherwise pin the testnet cursor above
     * every testnet ledger, and the testnet sync would return nothing forever.
     */
    public function testLastSyncedLedgerIndexIsScopedPerAccountAndNetwork(): void
    {
        $this->assertNull($this->repository->getLastSyncedLedgerIndex(self::DESTINATION_ACCOUNT, 'testnet'));

        $this->repository->saveTransactions([
            $this->givenTransaction('HASH_TESTNET', '20000000', 10001),
            $this->givenTransaction('HASH_MAINNET', '100000000', 10002, 'mainnet'),
            $this->givenTransaction('HASH_FOREIGN', '30000000', 10003, 'testnet', self::OTHER_ACCOUNT),
        ]);

        $this->assertSame('20000000', $this->repository->getLastSyncedLedgerIndex(self::DESTINATION_ACCOUNT, 'testnet'));
        $this->assertSame('100000000', $this->repository->getLastSyncedLedgerIndex(self::DESTINATION_ACCOUNT, 'mainnet'));
        $this->assertSame('30000000', $this->repository->getLastSyncedLedgerIndex(self::OTHER_ACCOUNT, 'testnet'));
        $this->assertNull($this->repository->getLastSyncedLedgerIndex(self::OTHER_ACCOUNT, 'mainnet'));
    }

    /**
     * The backfill reads the network out of the CTID rather than out of the
     * shop's configuration: a shop that tested on testnet before going live has
     * rows from both networks, and one guessed value for all of them poisons
     * the cursor this column exists to fix. Both CTIDs below are real.
     */
    public function testTheMigrationDerivesTheNetworkFromTheCtid(): void
    {
        $this->insertRawRow('HASH_CTID_TESTNET', 'C139B2B500020001');
        $this->insertRawRow('HASH_CTID_MAINNET', 'C65DF9C700380000');
        $this->insertRawRow('HASH_CTID_BROKEN', 'not-a-ctid');

        $migration = new Migration1788801424NetworkColumn();
        $migration->update($this->connection);

        $this->assertSame('testnet', $this->storedNetwork('HASH_CTID_TESTNET'));
        $this->assertSame('mainnet', $this->storedNetwork('HASH_CTID_MAINNET'));
        $this->assertSame('', $this->storedNetwork('HASH_CTID_BROKEN'));

        // Idempotent: a second run must not reclassify anything.
        $migration->update($this->connection);

        $this->assertSame('testnet', $this->storedNetwork('HASH_CTID_TESTNET'));
        $this->assertSame('mainnet', $this->storedNetwork('HASH_CTID_MAINNET'));
    }

    public function testFindExistingHashesReturnsOnlyWhatIsStored(): void
    {
        $this->repository->saveTransactions([$this->givenTransaction('HASH_STORED', '11', 10003)]);

        $this->assertSame([], $this->repository->findExistingHashes([]));
        $this->assertSame(
            ['HASH_STORED'],
            $this->repository->findExistingHashes(['HASH_STORED', 'HASH_UNKNOWN'])
        );
    }

    /**
     * Two syncs racing on the same ledger page must not blow up on the unique
     * hash index; the row is already there, which is the desired end state.
     */
    public function testStoringTheSameTransactionTwiceIsHarmless(): void
    {
        $transaction = $this->givenTransaction('HASH_DUPLICATE', '12', 10004);

        $this->repository->saveTransactions([$transaction]);
        $this->repository->saveTransactions([$transaction]);

        $storedRows = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `ledger_direct_xrpl_tx` WHERE `hash` = :hash',
            ['hash' => 'HASH_DUPLICATE']
        );

        $this->assertSame(1, (int) $storedRows);
    }

    private function givenTransaction(
        string $hash,
        string $ledgerIndex,
        int $destinationTag,
        string $network = 'testnet',
        string $destination = self::DESTINATION_ACCOUNT
    ): XrplTransaction {
        return new XrplTransaction(
            network: $network,
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            // Real CTIDs are 16 characters wide, and the column is sized for exactly that.
            ctid: strtoupper(substr(md5($hash), 0, 16)),
            account: 'rSenderAccount',
            destination: $destination,
            destinationTag: $destinationTag,
            date: 0,
            meta: ['delivered_amount' => '40000000'],
            tx: ['TransactionType' => 'Payment'],
        );
    }

    /**
     * Bypasses the repository on purpose: this is what a row looks like before
     * the migration has classified it.
     */
    private function insertRawRow(string $hash, string $ctid): void
    {
        $this->connection->insert('ledger_direct_xrpl_tx', [
            'id' => Uuid::randomBytes(),
            'network' => '',
            'ledger_index' => 42,
            'hash' => $hash,
            'ctid' => $ctid,
            'account' => 'rSenderAccount',
            'destination' => self::DESTINATION_ACCOUNT,
            'destination_tag' => 10005,
            'date' => 0,
            'meta' => '{}',
            'tx' => '{}',
        ]);
    }

    private function storedNetwork(string $hash): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT `network` FROM `ledger_direct_xrpl_tx` WHERE `hash` = :hash',
            ['hash' => $hash]
        );
    }
}
