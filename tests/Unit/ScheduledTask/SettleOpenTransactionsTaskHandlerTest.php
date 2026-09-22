<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\ScheduledTask;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\ScheduledTask\SettleOpenTransactionsTask;
use Hardcastle\LedgerDirect\ScheduledTask\SettleOpenTransactionsTaskHandler;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * One node request per receiving account and network, every open order
 * matched against the table, the ones that settle moved to paid.
 */
class SettleOpenTransactionsTaskHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private OrderTransactionService $orderTransactionService;

    private OrderTransactionStateHandler $stateHandler;

    protected function setUp(): void
    {
        $this->orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
    }

    public function testTheTaskRunsEveryFiveMinutes(): void
    {
        $this->assertSame('ledger_direct.settle_open_transactions', SettleOpenTransactionsTask::getTaskName());
        $this->assertSame(300, SettleOpenTransactionsTask::getDefaultInterval());
    }

    public function testOneSyncPerAccountThenEveryOpenOrderIsMatched(): void
    {
        $first = $this->transaction('tx-1', 'open');
        $second = $this->transaction('tx-2', 'paid_partially');
        $onMainnet = $this->transaction('tx-3', 'open');

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([$first, $second, $onMainnet]));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($first)->andReturn($this->quote('testnet'));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($second)->andReturn($this->quote('testnet'));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($onMainnet)->andReturn($this->quote('mainnet'));

        // Two orders on the testnet account, one on the mainnet account: two syncs, both unthrottled.
        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('rMerchant', 'testnet', false);
        $this->orderTransactionService->shouldReceive('syncLedger')->once()->with('rMerchant', 'mainnet', false);
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->never();

        // The first settles, the second is topped up to paid, the third is still waiting.
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($first, Mockery::type(Context::class))
            ->andReturn($this->quote('testnet')->withFulfillment('HASH1', 40.0, 'CTID'));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($second, Mockery::type(Context::class))
            ->andReturn($this->quote('testnet')->withFulfillment('HASH2', 40.0, 'CTID'));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($onMainnet, Mockery::type(Context::class))
            ->andReturn(null);

        $this->stateHandler->shouldReceive('paid')->once()->with('tx-1', Mockery::type(Context::class));
        $this->stateHandler->shouldReceive('paid')->once()->with('tx-2', Mockery::type(Context::class));
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->with('tx-1', Mockery::type(Context::class))->andReturn($this->transaction('tx-1', 'paid'));
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->with('tx-2', Mockery::type(Context::class))->andReturn($this->transaction('tx-2', 'paid'));

        $this->handler()->run();
    }

    public function testAnOrderThatThrowsDoesNotStopTheOthers(): void
    {
        $broken = $this->transaction('tx-broken', 'open');
        $fine = $this->transaction('tx-fine', 'open');

        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([$broken, $fine]));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($broken)->andReturn($this->quote('testnet'));
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($fine)->andReturn($this->quote('testnet'));
        $this->orderTransactionService->shouldReceive('syncLedger')->once();
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($broken, Mockery::any())
            ->andThrow(new \RuntimeException('node hiccup'));
        $this->orderTransactionService->shouldReceive('matchOrderTransaction')->with($fine, Mockery::any())
            ->andReturn($this->quote('testnet')->withFulfillment('HASH', 40.0, 'CTID'));
        $this->stateHandler->shouldReceive('paid')->once()->with('tx-fine', Mockery::any());
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->andReturn($this->transaction('tx-fine', 'paid'));

        $this->handler()->run();
    }

    public function testNothingOpenMeansNoNodeRequest(): void
    {
        $this->orderTransactionService->shouldReceive('findOpenLedgerDirectTransactions')
            ->once()->andReturn(new OrderTransactionCollection([]));
        $this->orderTransactionService->shouldReceive('syncLedger')->never();

        $this->handler()->run();
    }

    private function handler(): SettleOpenTransactionsTaskHandler
    {
        return new SettleOpenTransactionsTaskHandler(
            Mockery::mock(EntityRepository::class),
            new NullLogger(),
            $this->orderTransactionService,
            new PaymentStateService($this->stateHandler, $this->orderTransactionService, new SettlementPolicy(), new NullLogger())
        );
    }

    private function transaction(string $id, string $state): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setUniqueIdentifier($id);
        $transaction->setStateMachineState($stateEntity);

        return $transaction;
    }

    private function quote(string $network): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: $network,
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.5,
            amountRequested: 40.0,
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
            expiry: time() + 300,
        );
    }
}
