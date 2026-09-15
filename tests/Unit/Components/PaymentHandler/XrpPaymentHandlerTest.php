<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Components\PaymentHandler;

use Hardcastle\LedgerDirect\Components\PaymentHandler\XrpPaymentHandler;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The handler reads the stored PaymentIntent and lets the core's SettlementPolicy decide; what
 * is asserted here is the mapping of that decision onto Shopware's transaction states.
 */
class XrpPaymentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TX_ID = 'order-transaction-id';

    private RouterInterface $router;

    private OrderTransactionStateHandler $stateHandler;

    private OrderTransactionService $transactionService;

    private XrpPaymentHandler $handler;

    private Context $context;

    protected function setUp(): void
    {
        $this->router = Mockery::mock(RouterInterface::class);
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        $this->transactionService = Mockery::mock(OrderTransactionService::class);

        $this->handler = new XrpPaymentHandler($this->router, $this->stateHandler, $this->transactionService, new SettlementPolicy());
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

        $orderTransaction = Mockery::mock(OrderTransactionEntity::class);
        $orderTransaction->shouldReceive('getOrder')->andReturn($order);

        $this->transactionService->shouldReceive('getOrderTransactionById')
            ->with(self::TX_ID, $this->context)
            ->andReturn($orderTransaction);
        $this->transactionService->shouldReceive('prepareOrderTransactionForXrpl')
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
        $this->givenStoredIntent($this->xrpQuote(100.0)->withFulfillment('HASH', 100.0, 'CTID'));
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    /**
     * The core tolerates 0.15 % on the native asset; the handler must not add a stricter check.
     */
    public function testFinalizeMarksPaidWithinTheCoreTolerance(): void
    {
        $this->givenStoredIntent($this->xrpQuote(100.0)->withFulfillment('HASH', 99.85, 'CTID'));
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeMarksPartiallyPaidWhenUnderpaidBeyondTolerance(): void
    {
        $this->givenStoredIntent($this->xrpQuote(100.0)->withFulfillment('HASH', 90.0, 'CTID'));
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeReopensWhenNoTransactionOnLedger(): void
    {
        $this->givenStoredIntent($this->xrpQuote(100.0)); // quoted, nothing arrived
        $this->stateHandler->shouldReceive('reopen')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeReopensWhenTheOrderWasNeverQuoted(): void
    {
        $this->givenStoredIntent(null);
        $this->stateHandler->shouldReceive('reopen')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    private function finalize(): void
    {
        $this->handler->finalize(new Request(), new PaymentTransactionStruct(self::TX_ID), $this->context);
    }

    private function givenStoredIntent(?PaymentIntent $intent): void
    {
        $orderTransaction = Mockery::mock(OrderTransactionEntity::class);

        $this->transactionService->shouldReceive('getOrderTransactionById')
            ->with(self::TX_ID, $this->context)
            ->andReturn($orderTransaction);
        $this->transactionService->shouldReceive('readPaymentIntent')
            ->with($orderTransaction)
            ->andReturn($intent);
    }

    private function xrpQuote(float $requested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.5,
            amountRequested: $requested,
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
        );
    }
}
