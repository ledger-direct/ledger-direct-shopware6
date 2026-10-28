<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Components\PaymentHandler;

use Hardcastle\LedgerDirect\Components\PaymentHandler\StellarPaymentHandler;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\HeadlessTargets;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Stellar handler is the XRPL handler under another name: the stored PaymentIntent names the
 * chain, the core's SettlementPolicy decides, and the mapping onto Shopware's transaction states is
 * the same. Asserted here for a Stellar intent (no CTID on this chain).
 */
class StellarPaymentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TX_ID = 'order-transaction-id';

    private RouterInterface $router;

    private OrderTransactionStateHandler $stateHandler;

    private OrderTransactionService $transactionService;

    private StellarPaymentHandler $handler;

    private Context $context;

    protected function setUp(): void
    {
        $this->router = Mockery::mock(RouterInterface::class);
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        $this->transactionService = Mockery::mock(OrderTransactionService::class);

        $this->handler = new StellarPaymentHandler($this->router, $this->transactionService, $this->paymentStateService(), new HeadlessTargets(ConfigurationServiceMock::createInstance([])));
        $this->context = new Context(new SystemSource());
    }

    public function testSupportsReturnsFalse(): void
    {
        $this->assertFalse($this->handler->supports(PaymentHandlerType::REFUND, 'payment-method-id', $this->context));
        $this->assertFalse($this->handler->supports(PaymentHandlerType::RECURRING, 'payment-method-id', $this->context));
    }

    /**
     * The redirect carries the order's deepLinkCode: it is what lets a guest
     * open the payment page and poll the status without a login.
     */
    public function testPayRedirectsToThePaymentPageWithTheOrderSecret(): void
    {
        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setDeepLinkCode('the-deep-link-code');
        $order->setSalesChannelId('sales-channel-id');

        $orderTransaction = Mockery::mock(OrderTransactionEntity::class);
        $orderTransaction->shouldReceive('getOrder')->andReturn($order);

        $this->transactionService->shouldReceive('getOrderTransactionById')
            ->with(self::TX_ID, $this->context)
            ->andReturn($orderTransaction);
        $this->transactionService->shouldReceive('prepareOrderTransaction')
            ->once()
            ->with($order, $orderTransaction, $this->context);
        $this->router->shouldReceive('generate')
            ->once()
            ->with('frontend.checkout.ledger-direct.payment', [
                'orderId' => 'order-id',
                'returnUrl' => 'https://shop.example/payment/finalize-transaction?_sw_payment_token=t',
                'deepLinkCode' => 'the-deep-link-code',
            ])
            ->andReturn('/ledger-direct/payment/order-id?returnUrl=...&deepLinkCode=the-deep-link-code');

        $response = $this->handler->pay(
            new Request(),
            new PaymentTransactionStruct(self::TX_ID, 'https://shop.example/payment/finalize-transaction?_sw_payment_token=t'),
            $this->context,
            null
        );

        $this->assertNotNull($response);
        $this->assertStringContainsString('deepLinkCode=the-deep-link-code', $response->getTargetUrl());
    }

    public function testFinalizeMarksPaidWhenFullyPaid(): void
    {
        $this->givenStoredIntent($this->xlmQuote(100.0)->withFulfillment('HASH', 100.0));
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    /**
     * The core tolerates 0.15 % on the native asset; the handler must not add a stricter check.
     */
    public function testFinalizeMarksPaidWithinTheCoreTolerance(): void
    {
        $this->givenStoredIntent($this->xlmQuote(100.0)->withFulfillment('HASH', 99.85));
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeMarksPartiallyPaidWhenUnderpaidBeyondTolerance(): void
    {
        $this->givenStoredIntent($this->xlmQuote(100.0)->withFulfillment('HASH', 90.0));
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeReopensWhenNoTransactionOnLedger(): void
    {
        $this->givenStoredIntent($this->xlmQuote(100.0)); // quoted, nothing arrived
        $this->stateHandler->shouldReceive('reopen')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeReopensWhenTheOrderWasNeverQuoted(): void
    {
        $this->givenStoredIntent(null);
        $this->stateHandler->shouldReceive('reopen')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    /**
     * The status endpoint set `paid` before the customer came back. Shopware has no paid → paid
     * transition, so a second attempt would throw and Shopware would fail the transaction.
     */
    public function testFinalizeLeavesAnAlreadyPaidTransactionAlone(): void
    {
        $this->givenStoredIntent($this->xlmQuote(100.0)->withFulfillment('HASH', 100.0), state: 'paid');
        $this->stateHandler->shouldReceive('paid')->never();
        $this->stateHandler->shouldReceive('paidPartially')->never();

        $this->finalize();
    }

    /**
     * A top-up after a partial payment: paid_partially → paid is a transition Shopware allows.
     */
    public function testFinalizeMovesAPartiallyPaidTransactionToPaid(): void
    {
        $this->givenStoredIntent($this->xlmQuote(100.0)->withFulfillment('HASH', 100.0), state: 'paid_partially');
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    /**
     * open → open is not a transition either: a customer returning to an open transaction
     * without having paid leaves it open.
     */
    public function testFinalizeDoesNotReopenAnOpenTransaction(): void
    {
        $this->givenStoredIntent($this->xlmQuote(100.0), state: 'open');
        $this->stateHandler->shouldReceive('reopen')->never();

        $this->finalize();
    }

    /**
     * A transaction the merchant cancelled is not reopened by a late click on the returnUrl.
     */
    public function testFinalizeDoesNotReopenACancelledTransaction(): void
    {
        $this->givenStoredIntent($this->xlmQuote(100.0), state: 'cancelled');
        $this->stateHandler->shouldReceive('reopen')->never();

        $this->finalize();
    }

    private function finalize(): void
    {
        $this->handler->finalize(new Request(), new PaymentTransactionStruct(self::TX_ID), $this->context);
    }

    /**
     * The real state service over the mocked Shopware state handler: what is
     * asserted is which transition the handler asks Shopware for.
     */
    private function paymentStateService(): PaymentStateService
    {
        return new PaymentStateService($this->stateHandler, $this->transactionService, new SettlementPolicy(), new NullLogger());
    }

    /**
     * @param string $state the transaction's state when the customer comes back — Shopware parks
     *     an asynchronous payment in `unconfirmed` while the customer is away
     */
    private function givenStoredIntent(?PaymentIntent $intent, string $state = 'unconfirmed'): void
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $orderTransaction = Mockery::mock(OrderTransactionEntity::class);
        $orderTransaction->shouldReceive('getId')->andReturn(self::TX_ID);
        $orderTransaction->shouldReceive('getStateMachineState')->andReturn($stateEntity);

        $this->transactionService->shouldReceive('getOrderTransactionById')
            ->with(self::TX_ID, $this->context)
            ->andReturn($orderTransaction);
        $this->transactionService->shouldReceive('readPaymentIntent')
            ->with($orderTransaction)
            ->andReturn($intent);
    }

    private function xlmQuote(float $requested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'stellar-xlm-payment',
            chain: 'STELLAR',
            network: 'testnet',
            baseAsset: 'XLM',
            quoteCurrency: 'EUR',
            pairing: 'XLM/EUR',
            exchangeRate: 2.5,
            amountRequested: $requested,
            destinationAccount: 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA',
            destinationTag: 114729,
        );
    }
}
