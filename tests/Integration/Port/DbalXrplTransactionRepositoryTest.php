<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Port;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Port\DbalXrplTransactionRepository;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class DbalXrplTransactionRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour; // wraps each test in a rolled-back DB transaction

    private const DESTINATION_ACCOUNT = 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr';

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
     * The port contract: 0 the first time an account is asked, strictly
     * increasing after that. The core turns this into the actual tag.
     */
    public function testDestinationTagSequenceStartsAtZeroAndIncrements(): void
    {
        $this->assertSame(0, $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT));
        $this->assertSame(1, $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT));
        $this->assertSame(2, $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT));
    }

    public function testDestinationTagSequenceIsCountedPerAccount(): void
    {
        $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT);
        $this->repository->nextDestinationTagSequence(self::DESTINATION_ACCOUNT);

        $this->assertSame(0, $this->repository->nextDestinationTagSequence('rSomeOtherMerchantAccount'));
    }

    /**
     * XRPL destination tags are unsigned 32-bit, and the core issues them
     * across that whole range. On the previous signed INT(11) column anything
     * above 2147483647 was truncated or rejected — and a truncated tag means
     * the customer's payment is never matched to their order.
     */
    public function testATagAboveTheSignedIntegerLimitSurvivesStorage(): void
    {
        $destinationTag = 4294967295;
        $this->assertGreaterThan(2147483647, $destinationTag);

        $this->repository->saveTransactions([$this->givenTransaction('HASH_HIGH_TAG', '90', $destinationTag)]);

        $found = $this->repository->findTransaction(self::DESTINATION_ACCOUNT, $destinationTag);

        $this->assertNotNull($found);
        $this->assertSame($destinationTag, $found->destinationTag);
        $this->assertSame('HASH_HIGH_TAG', $found->hash);
        $this->assertSame(40.0, $found->getDeliveredAmount());
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

            $found = $this->repository->findTransaction(self::DESTINATION_ACCOUNT, $destinationTag);

            $this->assertNotNull($found, 'tag ' . $destinationTag . ' was not stored as issued');
            $this->assertSame($destinationTag, $found->destinationTag);
        }
    }

    /**
     * MAX() over the old VARCHAR column sorted lexicographically, so "9" beat
     * "10" and the sync would have resumed at the wrong ledger on the next
     * digit rollover.
     */
    public function testLastSyncedLedgerIndexIsOrderedNumerically(): void
    {
        $this->assertNull($this->repository->getLastSyncedLedgerIndex());

        $this->repository->saveTransactions([
            $this->givenTransaction('HASH_9', '9', 10001),
            $this->givenTransaction('HASH_10', '10', 10002),
        ]);

        $this->assertSame('10', $this->repository->getLastSyncedLedgerIndex());
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

    private function givenTransaction(string $hash, string $ledgerIndex, int $destinationTag): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            // Real CTIDs are 16 characters wide, and the column is sized for exactly that.
            ctid: strtoupper(substr(md5($hash), 0, 16)),
            account: 'rSenderAccount',
            destination: self::DESTINATION_ACCOUNT,
            destinationTag: $destinationTag,
            date: 0,
            meta: ['delivered_amount' => '40000000'],
            tx: ['TransactionType' => 'Payment'],
        );
    }
}
