<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\DependencyInjection;

use Hardcastle\LedgerDirect\Core\Xrpl\RefundReconciler;
use Hardcastle\LedgerDirect\Core\Xrpl\RefundService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Port\DbalRefundRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * The refund services are wired by hand, once per chain that speaks the XRPL
 * protocol. The same `r…` address can pay refunds on both chains, so every
 * part of a chain's stack has to be that chain's: a Xahau refund service on
 * the XRP Ledger's node would look for the payment on the wrong ledger, and
 * on the XRP Ledger's table it would count the wrong refunds against a
 * payment's amount. Nothing would fail; it would only be wrong.
 */
class RefundWiringTest extends TestCase
{
    use KernelTestBehaviour;

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function chains(): iterable
    {
        yield 'XRP Ledger' => [[
            'chain' => 'XRPL',
            'service' => RefundService::class,
            'reconciler' => RefundReconciler::class,
            'repository' => DbalRefundRepository::class,
            'throttle' => 'ledger_direct.xrpl.refund_throttle',
            'table' => 'ledger_direct_xrpl_refund',
            'paymentThrottle' => SyncThrottle::class,
            'paymentSync' => SyncService::class,
        ]];
        yield 'Xahau' => [[
            'chain' => 'XAHAU',
            'service' => 'ledger_direct.xahau.refunds',
            'reconciler' => 'ledger_direct.xahau.refund_reconciler',
            'repository' => 'ledger_direct.xahau.refund_repository',
            'throttle' => 'ledger_direct.xahau.refund_throttle',
            'table' => 'ledger_direct_xahau_refund',
            'paymentThrottle' => 'ledger_direct.xahau.sync_throttle',
            'paymentSync' => 'ledger_direct.xahau.sync',
        ]];
    }

    /**
     * @param array<string, string> $ids
     */
    #[DataProvider('chains')]
    public function testTheRefundServiceIsOnItsOwnChainsNodeAndTables(array $ids): void
    {
        $service = $this->getContainer()->get($ids['service']);
        $repository = $this->getContainer()->get($ids['repository']);
        $paymentSync = $this->getContainer()->get($ids['paymentSync']);

        $this->assertInstanceOf(RefundService::class, $service);
        $this->assertInstanceOf(DbalRefundRepository::class, $repository);
        $this->assertSame($ids['chain'], self::property($service, 'chain'));
        $this->assertSame($ids['chain'], self::property($repository, 'chain'));
        $this->assertSame($ids['table'], self::property($repository, 'table'));

        // The node and the stored payments are the ones the payment sync of this chain uses.
        $client = self::property($paymentSync, 'xrplClient');
        $this->assertInstanceOf(XrplClient::class, $client);
        $this->assertSame($ids['chain'], self::property($client, 'chain'));
        $this->assertSame($client, self::property($service, 'client'));
        $this->assertSame($repository, self::property($service, 'refunds'));

        $receivedPayments = self::property($service, 'receivedPayments');
        $this->assertSame(self::property($paymentSync, 'transactionRepository'), self::property($receivedPayments, 'transactions'));
        $this->assertSame($repository, self::property($receivedPayments, 'refunds'));
    }

    /**
     * The service would build a reconciler of its own if it were given none,
     * and that one has no cache: every look at a refund would then read the
     * paying account's history from the oldest watched refund on.
     *
     * @param array<string, string> $ids
     */
    #[DataProvider('chains')]
    public function testTheServiceCancelsWithTheReconcilerTheScheduledTaskWillUse(array $ids): void
    {
        $service = $this->getContainer()->get($ids['service']);
        $reconciler = $this->getContainer()->get($ids['reconciler']);
        $throttle = $this->getContainer()->get($ids['paymentThrottle']);

        $this->assertInstanceOf(RefundReconciler::class, $reconciler);
        $this->assertSame($reconciler, self::property($service, 'reconciler'));
        $this->assertSame($ids['chain'], self::property($reconciler, 'chain'));
        $this->assertSame(self::property($service, 'client'), self::property($reconciler, 'client'));
        $this->assertSame(self::property($service, 'refunds'), self::property($reconciler, 'refunds'));

        $cache = self::property($reconciler, 'cache');
        $this->assertInstanceOf(CacheInterface::class, $cache, "{$ids['reconciler']} has no cache");
        $this->assertSame(self::property($throttle, 'cache'), $cache);
    }

    /**
     * Looking for a refund's payment and the payment sync read the same
     * account. With one throttle key for both, opening a refund right after
     * a payment page was polled would find nothing and say so.
     *
     * @param array<string, string> $ids
     */
    #[DataProvider('chains')]
    public function testTheRefundThrottleHasAKeySpaceOfItsOwn(array $ids): void
    {
        $throttle = $this->getContainer()->get($ids['throttle']);
        $paymentThrottle = $this->getContainer()->get($ids['paymentThrottle']);

        $this->assertInstanceOf(SyncThrottle::class, $throttle);
        $this->assertNotSame($paymentThrottle, $throttle);
        $this->assertSame('refund', self::property($throttle, 'purpose'));
        $this->assertSame('', self::property($paymentThrottle, 'purpose'));
        $this->assertSame($ids['chain'], self::property($throttle, 'chain'));
        $this->assertSame(self::property($paymentThrottle, 'cache'), self::property($throttle, 'cache'));
        $this->assertSame(self::property($paymentThrottle, 'intervalSeconds'), self::property($throttle, 'intervalSeconds'));
    }

    /**
     * With the installation's secret a refund's reference can be computed
     * again from the payment it refunds; without one it is random. Both
     * chains use the same secret: the chain is part of what is signed.
     *
     * @param array<string, string> $ids
     */
    #[DataProvider('chains')]
    public function testReferencesAreDerivedFromTheInstallationsSecret(array $ids): void
    {
        $secret = $this->getContainer()->getParameter('kernel.secret');

        $this->assertIsString($secret);
        $this->assertNotSame('', $secret);
        $this->assertSame($secret, self::property($this->getContainer()->get($ids['service']), 'referenceKey'));
    }

    private static function property(object $service, string $name): mixed
    {
        return (new \ReflectionProperty($service, $name))->getValue($service);
    }
}
