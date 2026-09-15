<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * The mapping of a settlement onto Shopware's transaction states, and its
 * idempotency: Shopware's state machine has no paid → paid transition, so
 * applying the same result twice must be a no-op, not an exception.
 */
class PaymentStateServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TX_ID = 'order-transaction-id';

    private OrderTransactionStateHandler $stateHandler;

    private OrderTransactionService $orderTransactionService;

    private Context $context;

    protected function setUp(): void
    {
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        $this->orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $this->context = new Context(new SystemSource());
    }

    #[DataProvider('openStates')]
    public function testASettledIntentMovesAnOpenTransactionToPaid(string $from): void
    {
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $applied = $this->service()->applyState($this->transaction($from), $this->settled(), $this->context);

        $this->assertSame('paid', $applied);
    }

    public static function openStates(): array
    {
        return [
            'open' => ['open'],
            'in_progress' => ['in_progress'],
            'unconfirmed' => ['unconfirmed'],
            'paid_partially' => ['paid_partially'],
            'reminded' => ['reminded'],
        ];
    }

    public function testAShortPaymentMovesAnOpenTransactionToPaidPartially(): void
    {
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);

        $applied = $this->service()->applyState($this->transaction('open'), $this->partial(), $this->context);

        $this->assertSame('paid_partially', $applied);
    }

    /**
     * Nothing credited, but money is there — Shopware has no closer state.
     */
    public function testAPaymentInTheWrongAssetMovesTheTransactionToPaidPartially(): void
    {
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);

        $applied = $this->service()->applyState($this->transaction('open'), $this->wrongAsset(), $this->context);

        $this->assertSame('paid_partially', $applied);
    }

    #[DataProvider('idempotentCases')]
    public function testAStateAlreadySetIsNotSetAgain(string $current, PaymentIntent $intent): void
    {
        $this->stateHandler->shouldReceive('paid')->never();
        $this->stateHandler->shouldReceive('paidPartially')->never();

        $this->assertNull($this->service()->applyState($this->transaction($current), $intent, $this->context));
    }

    public static function idempotentCases(): array
    {
        return [
            'paid stays paid' => ['paid', self::settled()],
            'paid_partially stays paid_partially' => ['paid_partially', self::partial()],
        ];
    }

    #[DataProvider('closedStates')]
    public function testAClosedTransactionKeepsItsState(string $current): void
    {
        $this->stateHandler->shouldReceive('paid')->never();
        $this->stateHandler->shouldReceive('paidPartially')->never();

        $this->assertNull($this->service()->applyState($this->transaction($current), $this->partial(), $this->context));
        $this->assertNull($this->service()->applyState($this->transaction($current), $this->settled(), $this->context));
    }

    public static function closedStates(): array
    {
        return [
            'cancelled' => ['cancelled'],
            'failed' => ['failed'],
            'refunded' => ['refunded'],
            'paid, by hand' => ['paid'],
        ];
    }

    public function testNothingArrivedChangesNothing(): void
    {
        $this->stateHandler->shouldReceive('paid')->never();
        $this->stateHandler->shouldReceive('paidPartially')->never();

        $this->assertNull($this->service()->applyState($this->transaction('open'), $this->quote(), $this->context));
    }

    public function testATransactionLoadedWithoutItsStateIsLeftAlone(): void
    {
        $this->stateHandler->shouldReceive('paid')->never();

        $transaction = Mockery::mock(OrderTransactionEntity::class);
        $transaction->shouldReceive('getId')->andReturn(self::TX_ID);
        $transaction->shouldReceive('getStateMachineState')->andReturn(null);

        $this->assertNull($this->service()->applyState($transaction, $this->settled(), $this->context));
    }

    /**
     * The one step the status endpoint, the payment page and the scheduled
     * task share: sync, apply, and hand back the transaction as it is now.
     */
    public function testSyncAndApplyReloadsTheTransactionAfterAStateChange(): void
    {
        $before = $this->transaction('open');
        $after = $this->transaction('paid');

        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')
            ->once()->with($before, $this->context, true)->andReturn($this->settled());
        $this->stateHandler->shouldReceive('paid')->once();
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')
            ->once()->with(self::TX_ID, $this->context)->andReturn($after);

        $this->assertSame($after, $this->service()->syncAndApply($before, $this->context, throttled: true));
    }

    public function testSyncAndApplyHandsBackTheSameTransactionWhileNothingArrived(): void
    {
        $transaction = $this->transaction('open');

        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->once()->andReturn(null);
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->never();

        $this->assertSame($transaction, $this->service()->syncAndApply($transaction, $this->context, throttled: false));
    }

    public function testSyncAndApplyDoesNotSyncAClosedTransaction(): void
    {
        $transaction = $this->transaction('cancelled');

        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->never();

        $this->assertSame($transaction, $this->service()->syncAndApply($transaction, $this->context, throttled: true));
    }

    public function testReopenOnlyFromTheStatesTheCustomerIsAwayIn(): void
    {
        $this->stateHandler->shouldReceive('reopen')->twice()->with(self::TX_ID, $this->context);

        $service = $this->service();
        $service->reopenIfAway($this->transaction('unconfirmed'), $this->context);
        $service->reopenIfAway($this->transaction('in_progress'), $this->context);
        $service->reopenIfAway($this->transaction('open'), $this->context);
        $service->reopenIfAway($this->transaction('cancelled'), $this->context);
        $service->reopenIfAway($this->transaction('paid'), $this->context);
    }

    private function service(): PaymentStateService
    {
        return new PaymentStateService($this->stateHandler, $this->orderTransactionService, new SettlementPolicy(), new NullLogger());
    }

    private function transaction(string $state): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $transaction = Mockery::mock(OrderTransactionEntity::class);
        $transaction->shouldReceive('getId')->andReturn(self::TX_ID);
        $transaction->shouldReceive('getStateMachineState')->andReturn($stateEntity);

        return $transaction;
    }

    private static function quote(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.5,
            amountRequested: 100.0,
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
        );
    }

    private static function settled(): PaymentIntent
    {
        return self::quote()->withFulfillment('HASH', 100.0, 'CTID');
    }

    private static function partial(): PaymentIntent
    {
        return self::quote()->withFulfillment('HASH', 60.0, 'CTID');
    }

    private static function wrongAsset(): PaymentIntent
    {
        $quoted = ['currency' => '524C555344000000000000000000000000000000', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'];

        return PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'USD',
            pairing: 'RLUSD/USD',
            exchangeRate: 1.0,
            amountRequested: $quoted + ['value' => '10.00'],
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
        )->withFulfillment('HASH', ['currency' => $quoted['currency'], 'issuer' => 'rSomeoneElse', 'value' => '10.00'], 'CTID');
    }
}
