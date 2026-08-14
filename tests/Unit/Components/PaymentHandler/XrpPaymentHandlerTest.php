<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Components\PaymentHandler;

use Hardcastle\LedgerDirect\Components\PaymentHandler\XrpPaymentHandler;
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

class XrpPaymentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TX_ID = 'order-transaction-id';

    private OrderTransactionStateHandler $stateHandler;

    private OrderTransactionService $transactionService;

    private XrpPaymentHandler $handler;

    private Context $context;

    protected function setUp(): void
    {
        $router = Mockery::mock(RouterInterface::class);
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        $this->transactionService = Mockery::mock(OrderTransactionService::class);

        $this->handler = new XrpPaymentHandler($router, $this->stateHandler, $this->transactionService);
        $this->context = new Context(new SystemSource());
    }

    public function testSupportsReturnsFalse(): void
    {
        $this->assertFalse($this->handler->supports(PaymentHandlerType::REFUND, 'payment-method-id', $this->context));
        $this->assertFalse($this->handler->supports(PaymentHandlerType::RECURRING, 'payment-method-id', $this->context));
    }

    public function testFinalizeMarksPaidWhenFullyPaid(): void
    {
        $this->givenLedgerDirectCustomFields([
            'hash' => 'HASH', 'ctid' => 'CTID',
            'amount_requested' => 100.0, 'delivered_amount' => 100.0,
        ]);
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);

        $this->handler->finalize(new Request(), new PaymentTransactionStruct(self::TX_ID), $this->context);
    }

    public function testFinalizeMarksPartiallyPaidWhenUnderpaidBeyondSlippage(): void
    {
        $this->givenLedgerDirectCustomFields([
            'hash' => 'HASH', 'ctid' => 'CTID',
            'amount_requested' => 100.0, 'delivered_amount' => 90.0,
        ]);
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);

        $this->handler->finalize(new Request(), new PaymentTransactionStruct(self::TX_ID), $this->context);
    }

    public function testFinalizeReopensWhenNoTransactionOnLedger(): void
    {
        $this->givenLedgerDirectCustomFields([]); // no hash / ctid yet
        $this->stateHandler->shouldReceive('reopen')->once()->with(self::TX_ID, $this->context);

        $this->handler->finalize(new Request(), new PaymentTransactionStruct(self::TX_ID), $this->context);
    }

    private function givenLedgerDirectCustomFields(array $ledgerDirect): void
    {
        $orderTransaction = Mockery::mock(OrderTransactionEntity::class);
        $orderTransaction->shouldReceive('getCustomFields')->andReturn(['ledger_direct' => $ledgerDirect]);

        $this->transactionService->shouldReceive('getOrderTransactionById')
            ->with(self::TX_ID, $this->context)
            ->andReturn($orderTransaction);
    }
}
