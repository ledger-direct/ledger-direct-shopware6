<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Port;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Core\Port\RefundRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Testing\RefundRepositoryContractTestCase;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundSchema;
use Hardcastle\LedgerDirect\LedgerDirect;
use Hardcastle\LedgerDirect\Port\DbalRefundRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * The core's contract for a refund repository, against the table the
 * migration created. Four of its cases are the only thing between two
 * simultaneous requests and a payment refunded or assigned twice, and they
 * hold only because the table has the core's unique indexes; the first test
 * below says that it has them.
 *
 * The XRP Ledger's table here; DbalXahauRefundRepositoryTest runs every case
 * again on Xahau's.
 */
class DbalRefundRepositoryTest extends RefundRepositoryContractTestCase
{
    use IntegrationTestBehaviour; // wraps each test in a rolled-back DB transaction

    protected function repository(): RefundRepositoryInterface
    {
        // Inside the rolled-back transaction, so starting from empty is safe.
        $this->connection()->executeStatement('DELETE FROM `' . $this->table() . '`');

        return new DbalRefundRepository($this->connection(), $this->chain());
    }

    /**
     * Columns, primary key and indexes are the core's, name for name. The
     * unique ones are the point: without `uniq_…_source` two simultaneous
     * refunds both fit under one payment's amount.
     */
    public function testTheMigrationCreatedTheTableAsTheCoreDescribesIt(): void
    {
        $definition = RefundSchema::tables($this->chain())[$this->table()];

        $this->assertSame(
            array_column($definition['columns'], 'name'),
            $this->connection()->fetchFirstColumn('SHOW COLUMNS FROM `' . $this->table() . '`')
        );

        $unique = [];
        $plain = [];
        foreach ($this->connection()->fetchAllAssociative('SHOW INDEX FROM `' . $this->table() . '`') as $index) {
            if ((int) $index['Non_unique'] === 0) {
                $unique[(string) $index['Key_name']][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
            } else {
                $plain[(string) $index['Key_name']][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
            }
        }
        $inOrder = static function (array $indexes): array {
            ksort($indexes);

            return array_map(static function (array $columns): array {
                ksort($columns);

                return array_values($columns);
            }, $indexes);
        };

        $expectedUnique = ['PRIMARY' => $definition['primary']] + $definition['unique'];
        ksort($expectedUnique);
        $expectedPlain = $definition['indexes'];
        ksort($expectedPlain);

        $this->assertSame($expectedUnique, $inOrder($unique), 'the unique indexes');
        $this->assertSame($expectedPlain, $inOrder($plain), 'the other indexes');
        $this->assertCount(4, $definition['unique'], 'the four rules only storage can hold');
    }

    /**
     * An account can pay refunds on both chains. A refund of one must never
     * be read as the other's: the row does not say which chain it is from.
     */
    public function testARefundIsStoredInItsOwnChainsTableOnly(): void
    {
        $repository = $this->repository();
        $otherChain = $this->chain() === 'XRPL' ? 'XAHAU' : 'XRPL';
        $otherTable = (string) array_key_first(RefundSchema::tables($otherChain));
        $this->connection()->executeStatement('DELETE FROM `' . $otherTable . '`');
        $other = new DbalRefundRepository($this->connection(), $otherChain);

        $refund = $this->refund(1);
        $this->assertTrue($repository->insert($refund));

        $this->assertNotNull($repository->find($refund->id));
        $this->assertNull($other->find($refund->id));
        $this->assertNull($other->findByReference($refund->network, $refund->reference));
        $this->assertSame([], $other->findBySource($refund->network, $refund->sourceHash));
        $this->assertSame([], $other->findByPlatformReference($refund->platformReference));
        $this->assertSame([], $other->findByDestination($refund->network, $refund->payingAccount, $refund->destination->account));
        $this->assertSame([], $other->findWatched($refund->network, $refund->payingAccount, 0));
        $this->assertSame([], $other->watchedAccounts($refund->network, 0));
        $this->assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM `' . $otherTable . '`'));
    }

    /**
     * What the core hands over arrives in the columns as it is: the tag as a
     * number or as null (no tag is not tag 0), the amount as the decimal
     * string, never rounded by a numeric column.
     */
    public function testTheRowHoldsTheTagAndTheAmountAsTheCoreGaveThem(): void
    {
        $repository = $this->repository();
        $this->assertTrue($repository->insert($this->refund(1, tag: null, amount: '0.000001')));
        $this->assertTrue($repository->insert($this->refund(2, tag: 0, amount: '1234567890.123456')));

        $rows = $this->connection()->fetchAllKeyValue(
            'SELECT `amount`, `destination_tag` FROM `' . $this->table() . '` ORDER BY `amount` ASC'
        );

        $this->assertSame(['0.000001', '1234567890.123456'], array_map('strval', array_keys($rows)));
        $this->assertNull($rows['0.000001']);
        $this->assertSame('0', (string) $rows['1234567890.123456']);
    }

    /**
     * The table is a record: an uninstall that removes the plugin's data
     * leaves it, like the destination-tag counter. With the row gone the
     * payment it refunded could be refunded in full again.
     */
    public function testARefundOutlivesAnUninstallThatRemovesThePluginsData(): void
    {
        $repository = $this->repository();
        $refund = $this->refund(1);
        $this->assertTrue($repository->insert($refund));

        $plugin = new LedgerDirect(true, \dirname(__DIR__, 3));
        $plugin->setContainer($this->getContainer());
        $plugin->uninstall(new UninstallContext(
            $plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '1.0.0',
            $this->createMock(MigrationCollection::class),
            false // keepUserData: the merchant asked for everything to go
        ));

        $this->assertEquals($refund, $repository->find($refund->id));
    }

    public function testAChainWithoutARefundTableIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DbalRefundRepository($this->connection(), 'STELLAR');
    }

    protected function table(): string
    {
        return (string) array_key_first(RefundSchema::tables($this->chain()));
    }

    private function connection(): Connection
    {
        return $this->getContainer()->get(Connection::class);
    }
}
