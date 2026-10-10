<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Api;

use Hardcastle\LedgerDirect\Api\PaymentInfoController;
use Hardcastle\LedgerDirect\Components\PaymentHandler\UsdcPaymentHandler;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;

/**
 * The admin route: our transaction with a readable record answers the card's
 * payload; anything else is refused without leaking what it is.
 */
class PaymentInfoControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const TX_ID = '0190c1d8a0e24c2b9c1e1a7b1d2e3f40';

    private OrderTransactionService $orderTransactionService;

    private Context $context;

    protected function setUp(): void
    {
        $this->orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $this->context = Context::createDefaultContext();
    }

    public function testOurTransactionWithARecordAnswersStatusAndDisplay(): void
    {
        $transaction = $this->transaction(UsdcPaymentHandler::class);
        $intent = $this->usdcQuote()->withFulfillment('HASH-HALF', ['currency' => '5553444300000000000000000000000000000000', 'value' => '10', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt']);
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($transaction)->andReturn($intent);

        $response = $this->controller()->info(self::TX_ID, $this->context);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('partial', $payload['status']['state']);
        self::assertSame('30', $payload['display']['shortfall'], 'the plain decimal as the core states it, no trailing zeros');
        self::assertSame('https://testnet.xrpl.org/transactions/HASH-HALF', $payload['display']['explorerUrl']);
    }

    public function testAnotherPaymentMethodsTransactionIs404(): void
    {
        $this->transaction('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\DefaultPayment');

        $response = $this->controller()->info(self::TX_ID, $this->context);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'not_a_ledger_direct_transaction'], json_decode((string) $response->getContent(), true));
    }

    public function testAnUnknownTransactionIs404(): void
    {
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->with(self::TX_ID, $this->context)->andReturn(null);

        self::assertSame(404, $this->controller()->info(self::TX_ID, $this->context)->getStatusCode());
    }

    public function testOurTransactionWithoutARecordIs404(): void
    {
        $transaction = $this->transaction(UsdcPaymentHandler::class);
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($transaction)->andReturn(null);

        $response = $this->controller()->info(self::TX_ID, $this->context);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'no_payment_intent'], json_decode((string) $response->getContent(), true));
    }

    public function testAnUnreadableRecordIs422(): void
    {
        $transaction = $this->transaction(UsdcPaymentHandler::class);
        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($transaction)->andThrow(new InvalidArgumentException('Missing schema_version'));

        $response = $this->controller()->info(self::TX_ID, $this->context);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'payment_intent_unreadable'], json_decode((string) $response->getContent(), true));
    }

    private function controller(): PaymentInfoController
    {
        return new PaymentInfoController($this->orderTransactionService, new SettlementPolicy());
    }

    private function transaction(string $handlerIdentifier): OrderTransactionEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('0190c1d8a0e24c2b9c1e1a7b1d2e3f41');
        $paymentMethod->setHandlerIdentifier($handlerIdentifier);

        $transaction = new OrderTransactionEntity();
        $transaction->setId(self::TX_ID);
        $transaction->setUniqueIdentifier(self::TX_ID);
        $transaction->setPaymentMethod($paymentMethod);

        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->with(self::TX_ID, $this->context)->andReturn($transaction);

        return $transaction;
    }

    private function usdcQuote(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'usdc-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'USDC', quoteCurrency: 'USD',
            pairing: 'USDC/USD', exchangeRate: 1.0,
            amountRequested: ['currency' => '5553444300000000000000000000000000000000', 'value' => '40.00', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'],
            destinationAccount: 'rMerchant', destinationTag: 114729, expiry: time() + 300,
        );
    }
}
