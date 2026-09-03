<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Components\PaymentHandler;

use Hardcastle\LedgerDirect\Components\PaymentHandler\UsdcPaymentHandler;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

class UsdcPaymentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TX_ID = 'order-transaction-id';

    /** The quoted token: on-ledger currency code and issuer from the core's registry (testnet). */
    private const TOKEN = ['currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    private OrderTransactionStateHandler $stateHandler;

    private OrderTransactionService $transactionService;

    private UsdcPaymentHandler $handler;

    private Context $context;

    protected function setUp(): void
    {
        $router = Mockery::mock(RouterInterface::class);
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        $this->transactionService = Mockery::mock(OrderTransactionService::class);

        $this->handler = new UsdcPaymentHandler($router, $this->stateHandler, $this->transactionService, new SettlementPolicy());
        $this->context = new Context(new SystemSource());
    }

    public function testSupportsReturnsFalse(): void
    {
        $this->assertFalse($this->handler->supports(PaymentHandlerType::REFUND, 'payment-method-id', $this->context));
        $this->assertFalse($this->handler->supports(PaymentHandlerType::RECURRING, 'payment-method-id', $this->context));
    }

    public function testFinalizeMarksPaidWhenAmountsMatch(): void
    {
        $this->givenStoredIntent($this->quote('1.16')->withFulfillment('HASH', self::TOKEN + ['value' => '1.16'], 'CTID'));
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeMarksPaidWhenOverpaid(): void
    {
        $this->givenStoredIntent($this->quote('1.16')->withFulfillment('HASH', self::TOKEN + ['value' => '2'], 'CTID'));
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeMarksPartiallyPaidWhenUnderpaid(): void
    {
        $this->givenStoredIntent($this->quote('1.16')->withFulfillment('HASH', self::TOKEN + ['value' => '1.10'], 'CTID'));
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    /**
     * A same-named token from another issuer is a different asset: the old strict array comparison
     * happened to reject it, the core rejects it by rule - it must never count as paid.
     */
    public function testFinalizeDoesNotMarkPaidForATokenFromAnotherIssuer(): void
    {
        $otherToken = ['currency' => '524C555344000000000000000000000000000000', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV', 'value' => '1.16'];
        $this->givenStoredIntent($this->quote('1.16')->withFulfillment('HASH', $otherToken, 'CTID'));
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);

        $this->finalize();
    }

    public function testFinalizeReopensWhenNoTransactionOnLedger(): void
    {
        $this->givenStoredIntent($this->quote('1.16')); // quoted, nothing arrived
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

    private function quote(string $requested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'usdc-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'USDC',
            quoteCurrency: 'USD',
            pairing: 'USDC/USD',
            exchangeRate: 1.0,
            amountRequested: self::TOKEN + ['value' => $requested],
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
        );
    }
}
