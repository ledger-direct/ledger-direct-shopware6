<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\SalesChannel;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Exception\NoPaymentIntentException;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * The status endpoint answers with the core's PaymentStatus payload for
 * each of the five states, plus `redirect` once the transaction is closed —
 * the same values PrestaShop's poll returns for the same situation.
 */
class PaymentRouteTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORDER_ID = 'order-id';

    private const TX_ID = 'order-transaction-id';

    private const QUOTED_TOKEN = ['currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    private OrderTransactionService $orderTransactionService;

    private OrderTransactionStateHandler $stateHandler;

    private RouterInterface $router;

    private Context $context;

    private SalesChannelContext $salesChannelContext;

    protected function setUp(): void
    {
        $this->orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $this->stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        $this->router = Mockery::mock(RouterInterface::class);
        $this->context = new Context(new SystemSource());

        $this->salesChannelContext = Mockery::mock(SalesChannelContext::class);
        $this->salesChannelContext->shouldReceive('getContext')->andReturn($this->context);
        // No session: the deepLinkCode on the request is what opens the order.
        $this->salesChannelContext->shouldReceive('getCustomer')->andReturn(null);
    }

    public function testWaitingCarriesTheSecondsLeftAndNoRedirect(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote(expiry: time() + 250)));
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->once()->andReturn(null);

        $payload = $this->check();

        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame('waiting', $payload['state']);
        $this->assertSame('XRP', $payload['base_asset']);
        $this->assertSame(40.0, $payload['amount_requested']);
        $this->assertNull($payload['amount_paid']);
        $this->assertNull($payload['shortfall']);
        $this->assertGreaterThan(200, $payload['seconds_left']);
        $this->assertArrayNotHasKey('redirect', $payload);
        $this->assertSame(
            ['schema_version', 'state', 'base_asset', 'amount_requested', 'amount_paid', 'shortfall', 'seconds_left'],
            array_keys($payload),
            'the seven contract fields, in contract order'
        );
    }

    public function testExpiredOnceTheQuoteHasPassedWithNothingPaid(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote(expiry: time() - 1)));
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->once()->andReturn(null);

        $payload = $this->check();

        $this->assertSame('expired', $payload['state']);
        $this->assertNull($payload['seconds_left']);
        $this->assertArrayNotHasKey('redirect', $payload);
    }

    /**
     * A short payment moves the transaction to paid_partially right here —
     * the merchant sees it without the customer ever coming back — and the
     * page keeps polling: no redirect.
     */
    public function testPartialSetsPaidPartiallyAndKeepsPolling(): void
    {
        $before = $this->transaction('open', $this->xrpQuote());
        $partial = $this->xrpQuote()->withFulfillment('HASH', 30.0, 'CTID');
        $after = $this->transaction('paid_partially', $partial);

        $this->givenAuthorisedOrder($before);
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')
            ->once()->with($before, $this->context, true)->andReturn($partial);
        $this->stateHandler->shouldReceive('paidPartially')->once()->with(self::TX_ID, $this->context);
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->once()->andReturn($after);

        $payload = $this->check();

        $this->assertSame('partial', $payload['state']);
        $this->assertSame(30.0, $payload['amount_paid']);
        $this->assertSame(10.0, $payload['shortfall']);
        $this->assertNull($payload['seconds_left']);
        $this->assertArrayNotHasKey('redirect', $payload);
    }

    public function testWrongAssetNamesTheDeliveredTokenAndTheFullShortfall(): void
    {
        $delivered = ['currency' => self::QUOTED_TOKEN['currency'], 'issuer' => 'rSomeoneElse', 'value' => '40.00'];
        $before = $this->transaction('open', $this->usdcQuote());
        $wrong = $this->usdcQuote()->withFulfillment('HASH', $delivered, 'CTID');
        $after = $this->transaction('paid_partially', $wrong);

        $this->givenAuthorisedOrder($before);
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->once()->andReturn($wrong);
        $this->stateHandler->shouldReceive('paidPartially')->once();
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->once()->andReturn($after);

        $payload = $this->check();

        $this->assertSame('wrong_asset', $payload['state']);
        $this->assertSame($delivered, $payload['amount_paid'], 'the delivered asset, so the page can name it');
        $this->assertEquals(self::QUOTED_TOKEN + ['value' => '40'], $payload['shortfall'], 'the whole request, in the quoted asset');
        $this->assertArrayNotHasKey('redirect', $payload);
    }

    public function testSettledSetsPaidAndRedirects(): void
    {
        $before = $this->transaction('open', $this->xrpQuote());
        $settled = $this->xrpQuote()->withFulfillment('HASH', 40.0, 'CTID');
        $after = $this->transaction('paid', $settled);

        $this->givenAuthorisedOrder($before);
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->once()->andReturn($settled);
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->once()->andReturn($after);
        $this->router->shouldReceive('generate')->andReturn('https://shop.example/account/order/the-code');

        $payload = $this->check();

        $this->assertSame('settled', $payload['state']);
        $this->assertSame(40.0, $payload['amount_paid']);
        $this->assertNull($payload['shortfall']);
        $this->assertSame('https://shop.example/account/order/the-code', $payload['redirect']);
    }

    /**
     * Second poll after settlement: nothing to sync, nothing to set, the
     * same answer — this is what makes the endpoint safe to poll.
     */
    public function testAPaidTransactionIsNeitherSyncedNorSetAgain(): void
    {
        $settled = $this->xrpQuote()->withFulfillment('HASH', 40.0, 'CTID');
        $this->givenAuthorisedOrder($this->transaction('paid', $settled));
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->never();
        $this->stateHandler->shouldReceive('paid')->never();
        $this->router->shouldReceive('generate')->andReturn('https://shop.example/account/order/the-code');

        $payload = $this->check();

        $this->assertSame('settled', $payload['state']);
        $this->assertArrayHasKey('redirect', $payload);
    }

    /**
     * Cancelled by the merchant: no contract state for that, but the page
     * must not keep the customer waiting — the redirect says so.
     */
    public function testATransactionClosedByTheMerchantRedirectsWhateverTheContractState(): void
    {
        $this->givenAuthorisedOrder($this->transaction('cancelled', $this->xrpQuote(expiry: time() + 250)));
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->never();
        $this->router->shouldReceive('generate')->andReturn('https://shop.example/account/order/the-code');

        $payload = $this->check();

        $this->assertSame('waiting', $payload['state']);
        $this->assertSame('https://shop.example/account/order/the-code', $payload['redirect']);
    }

    public function testAnUnauthorisedRequestIsForbidden(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote()), deepLinkCode: 'another-code');
        $this->orderTransactionService->shouldReceive('syncOrderTransactionWithXrpl')->never();

        try {
            $this->check();
            $this->fail('expected a 403');
        } catch (CartException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function testAnOrderNeverQuotedForXrplIsNotFound(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', null));

        $this->expectException(NoPaymentIntentException::class);

        $this->check();
    }

    /**
     * @return array<string, mixed>
     */
    private function check(?string $returnUrl = null): array
    {
        $request = new Request(['deepLinkCode' => 'the-code'] + ($returnUrl === null ? [] : ['returnUrl' => $returnUrl]));

        $response = $this->route()->check(self::ORDER_ID, $request, $this->salesChannelContext);

        return $response->getObject()->all();
    }

    private function route(): PaymentRoute
    {
        $settlementPolicy = new SettlementPolicy();

        return new PaymentRoute(
            $this->orderTransactionService,
            new OrderAccessGuard($this->orderTransactionService),
            $settlementPolicy,
            new PaymentStateService($this->stateHandler, $this->orderTransactionService, $settlementPolicy, new NullLogger()),
            new PaymentRedirect($this->router)
        );
    }

    private function givenAuthorisedOrder(OrderTransactionEntity $orderTransaction, string $deepLinkCode = 'the-code'): void
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setDeepLinkCode($deepLinkCode);
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));

        $this->orderTransactionService->shouldReceive('getOrderWithTransactions')
            ->with(self::ORDER_ID, $this->context)
            ->andReturn($order);
    }

    private function transaction(string $state, ?PaymentIntent $intent): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TX_ID);
        $orderTransaction->setUniqueIdentifier(self::TX_ID);
        $orderTransaction->setStateMachineState($stateEntity);

        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($orderTransaction)->andReturn($intent);

        return $orderTransaction;
    }

    private function xrpQuote(?int $expiry = null): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.5,
            amountRequested: 40.0,
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
            expiry: $expiry ?? time() + 300,
        );
    }

    private function usdcQuote(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'usdc-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'USDC',
            quoteCurrency: 'USD',
            pairing: 'USDC/USD',
            exchangeRate: 1.0,
            amountRequested: self::QUOTED_TOKEN + ['value' => '40.00'],
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
            expiry: time() + 300,
        );
    }
}
