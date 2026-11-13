<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Components\PaymentHandler;

use Hardcastle\LedgerDirect\Components\PaymentHandler\InterledgerPaymentHandler;
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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Interledger handler is the chain handler under another name, like the
 * XRPL's and Stellar's: the stored PaymentIntent names the chain, the core's
 * SettlementPolicy decides, and the transitions are the same.
 *
 * What is worth asserting here is that an Interledger record goes through
 * that machinery unchanged: the amount is always an issued currency (so the
 * exact comparison applies, not the native-asset tolerance), and the hash is
 * an incoming payment id rather than a transaction hash.
 */
class InterledgerPaymentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TX_ID = 'order-transaction-id';

    private const WALLET = 'https://ilp.example.test/merchant';

    private const INCOMING_PAYMENT = 'https://ilp.example.test/0102/incoming-payments/cc294351-d4e2';

    private RouterInterface $router;

    private OrderTransactionStateHandler $stateHandler;

    private OrderTransactionService $transactionService;

    private InterledgerPaymentHandler $handler;

    private Context $context;

    protected function setUp(): void
    {
        $this->router = Mockery::mock(RouterInterface::class);
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        $this->transactionService = Mockery::mock(OrderTransactionService::class);

        $this->handler = new InterledgerPaymentHandler(
            $this->router,
            $this->transactionService,
            new PaymentStateService($this->stateHandler, $this->transactionService, new SettlementPolicy(), new NullLogger()),
            new HeadlessTargets(ConfigurationServiceMock::createInstance([])),
        );
        $this->context = new Context(new SystemSource());
    }

    public function testSupportsReturnsFalse(): void
    {
        $this->assertFalse($this->handler->supports(PaymentHandlerType::REFUND, 'payment-method-id', $this->context));
        $this->assertFalse($this->handler->supports(PaymentHandlerType::RECURRING, 'payment-method-id', $this->context));
    }

    public function testPayQuotesTheOrderAndRedirectsToThePaymentPage(): void
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
        // Creating the incoming payment happens here, inside the core's quoting call.
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
            ->andReturn('/ledger-direct/payment/order-id?deepLinkCode=the-deep-link-code');

        $response = $this->handler->pay(
            new Request(),
            new PaymentTransactionStruct(self::TX_ID, 'https://shop.example/payment/finalize-transaction?_sw_payment_token=t'),
            $this->context,
            null
        );

        $this->assertNotNull($response);
        $this->assertStringContainsString('deepLinkCode=the-deep-link-code', $response->getTargetUrl());
    }

    public function testFinalizeMarksPaidWhenTheIncomingPaymentReceivedTheFullAmount(): void
    {
        $this->givenStoredIntent($this->quote('40.00')->withFulfillment('cc294351-d4e2', $this->amount('40.00')));
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    /**
     * An issued currency is compared exactly: the native-asset tolerance
     * never applies over Interledger, where every amount is issued by the
     * wallet provider.
     */
    public function testFinalizeMarksPartiallyPaidOnACentShort(): void
    {
        $this->givenStoredIntent($this->quote('40.00')->withFulfillment('cc294351-d4e2', $this->amount('39.99')));
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeReopensWhenNothingArrived(): void
    {
        $this->givenStoredIntent($this->quote('40.00'));
        $this->stateHandler->shouldReceive('reopen')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeMovesAPartiallyPaidTransactionToPaidAfterATopUp(): void
    {
        $this->givenStoredIntent(
            $this->quote('40.00')->withFulfillment('cc294351-d4e2', $this->amount('40.00')),
            state: 'paid_partially',
        );
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeLeavesAnAlreadyPaidTransactionAlone(): void
    {
        $this->givenStoredIntent(
            $this->quote('40.00')->withFulfillment('cc294351-d4e2', $this->amount('40.00')),
            state: 'paid',
        );
        $this->stateHandler->shouldReceive('paid')->never();
        $this->stateHandler->shouldReceive('paidPartially')->never();

        $this->finalize();
    }

    private function finalize(): void
    {
        $this->handler->finalize(new Request(), new PaymentTransactionStruct(self::TX_ID), $this->context);
    }

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

    private function quote(string $requested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'interledger-eur-payment',
            chain: 'INTERLEDGER',
            network: 'testnet',
            baseAsset: 'EUR',
            quoteCurrency: 'EUR',
            pairing: 'EUR/EUR',
            exchangeRate: 1.0,
            amountRequested: $this->amount($requested),
            destinationAccount: self::INCOMING_PAYMENT,
            destinationTag: 114729,
        );
    }

    /** @return array{currency: string, value: string, issuer: string} */
    private function amount(string $value): array
    {
        return ['currency' => 'EUR', 'value' => $value, 'issuer' => self::WALLET];
    }
}
