<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\Refund\Refund;
use Hardcastle\LedgerDirect\Core\Refund\RefundDestination;
use Hardcastle\LedgerDirect\Core\Xrpl\LedgerAmount;
use Hardcastle\LedgerDirect\Migration\Migration1795795269RefundTables;
use Hardcastle\LedgerDirect\Port\DbalRefundRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * A refund's row holds what is nowhere on the ledger: which order a payment
 * was for, who confirmed the destination, whether it is booked. Lost, the
 * payment it refunded could be refunded in full again. An uninstall makes
 * Shopware forget the plugin's migrations, and the next install runs them
 * again, over the rows that are there: the step must leave them alone.
 * (That the uninstall itself leaves them is in DbalRefundRepositoryTest.)
 *
 * Without a wrapping transaction, unlike the other integration tests: the
 * migration is DDL, and DDL commits whatever a test has open. The test
 * writes one row under a reference of its own and removes it again, before
 * and after; it changes nothing else.
 */
class RefundTablesSurviveTest extends TestCase
{
    use KernelTestBehaviour;

    private const TABLES = ['XRPL' => 'ledger_direct_xrpl_refund', 'XAHAU' => 'ledger_direct_xahau_refund'];

    private const PLATFORM_REFERENCE = 'refund-tables-survive-test';

    protected function setUp(): void
    {
        // A run that died half-way left its row behind, and an open refund
        // with the same destination and amount refuses the next one.
        $this->removeOwnRows();
    }

    protected function tearDown(): void
    {
        $this->removeOwnRows();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function chains(): iterable
    {
        yield 'XRP Ledger' => ['XRPL'];
        yield 'Xahau' => ['XAHAU'];
    }

    #[DataProvider('chains')]
    public function testARefundOutlivesTheMigrationRunningAgain(string $chain): void
    {
        $refund = $this->storedRefund($chain);

        $migration = new Migration1795795269RefundTables();
        $migration->update($this->connection());
        $migration->updateDestructive($this->connection());

        $this->assertEquals($refund, (new DbalRefundRepository($this->connection(), $chain))->find($refund->id));
    }

    private function storedRefund(string $chain): Refund
    {
        $id = bin2hex(random_bytes(16));

        $refund = Refund::request(
            id: $id,
            chain: $chain,
            network: 'testnet',
            purpose: Refund::PURPOSE_ORDER_REFUND,
            platformReference: self::PLATFORM_REFERENCE,
            sourceHash: strtoupper(hash('sha256', 'source-' . $id)),
            serial: 1,
            payingAccount: 'rHKho3gASqL4SQYjnrrKdAoq2a3vyyLLKL',
            destination: new RefundDestination('rD8GY2xd9q8aRtfYqBWdngj2TsF2wL5Jnk', 4711, RefundDestination::ORIGIN_SENDER, null),
            amount: LedgerAmount::native('1.07'),
            reference: strtoupper(hash('sha256', 'reference-' . $id)),
            createdAt: 1_800_000_000,
            createdLedger: 22_475_000,
            createdBy: 'admin',
        );

        $this->assertTrue((new DbalRefundRepository($this->connection(), $chain))->insert($refund));

        return $refund;
    }

    private function removeOwnRows(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection()->executeStatement(
                'DELETE FROM `' . $table . '` WHERE `platform_reference` = :reference',
                ['reference' => self::PLATFORM_REFERENCE]
            );
        }
    }

    private function connection(): Connection
    {
        return $this->getContainer()->get(Connection::class);
    }
}
