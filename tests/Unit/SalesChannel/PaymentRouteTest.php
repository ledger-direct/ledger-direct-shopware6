<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\SalesChannel;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Exception\NoPaymentIntentException;
use Hardcastle\LedgerDirect\Exception\TransactionLifetimeException;
use Hardcastle\LedgerDirect\Presentation\PaymentPageView;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Service\HeadlessTargets;
use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
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

        // No session: the deepLinkCode on the request is what opens the order.
        $this->salesChannelContext = $this->salesChannelContext(customer: null);
    }

    private function salesChannelContext(?CustomerEntity $customer): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-id');
        $salesChannel->setName('Shop');

        /** @var SalesChannelContext&Mockery\MockInterface $context */
        $context = Mockery::mock(SalesChannelContext::class);
        $context->shouldReceive('getContext')->andReturn($this->context);
        $context->shouldReceive('getCustomer')->andReturn($customer);
        $context->shouldReceive('getSalesChannel')->andReturn($salesChannel);

        return $context;
    }

    public function testWaitingCarriesTheSecondsLeftAndNoRedirect(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote(expiry: time() + 250)));
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);

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
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);

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
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')
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
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn($wrong);
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
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn($settled);
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->once()->andReturn($after);
        $this->router->shouldReceive('generate')->andReturn('https://shop.example/account/order/the-code');

        $payload = $this->check();

        $this->assertSame('settled', $payload['state']);
        $this->assertSame(40.0, $payload['amount_paid']);
        $this->assertNull($payload['shortfall']);
        $this->assertSame('HASH', $payload['hash'], 'the payment on record, for the success view and a front end\'s order page');
        $this->assertSame('https://shop.example/account/order/the-code', $payload['redirect']);
        $this->assertSame(['schema_version', 'state', 'base_asset', 'amount_requested', 'amount_paid', 'shortfall', 'seconds_left', 'hash', 'redirect'], array_keys($payload), 'the contract, then the platform\'s additions');
    }

    /**
     * Second poll after settlement: nothing to sync, nothing to set, the
     * same answer — this is what makes the endpoint safe to poll.
     */
    public function testAPaidTransactionIsNeitherSyncedNorSetAgain(): void
    {
        $settled = $this->xrpQuote()->withFulfillment('HASH', 40.0, 'CTID');
        $this->givenAuthorisedOrder($this->transaction('paid', $settled));
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();
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
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();
        $this->router->shouldReceive('generate')->andReturn('https://shop.example/account/order/the-code');

        $payload = $this->check();

        $this->assertSame('waiting', $payload['state']);
        $this->assertSame('https://shop.example/account/order/the-code', $payload['redirect']);
    }

    public function testAnUnauthorisedRequestIsForbidden(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote()), deepLinkCode: 'another-code');
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();

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

    /*
     * The quote endpoint — what a headless front end builds its payment page
     * from. Guarded like check: the code, or the session customer.
     */

    public function testQuoteForAGuestWithTheCodeCarriesTheOldFieldsAndThePage(): void
    {
        $intent = $this->xrpQuote();
        $this->givenAuthorisedOrder($this->transaction('open', $intent));
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);

        $payload = $this->quote(['deepLinkCode' => 'the-code']);

        // The Store API contract from the first release, as it was.
        $this->assertSame(self::ORDER_ID, $payload['orderId']);
        $this->assertSame('10010', $payload['orderNumber']);
        $this->assertSame('EUR', $payload['currencyCode']);
        $this->assertSame('€', $payload['currencySymbol']);
        $this->assertSame(10.0, $payload['price']);
        $this->assertSame('rMerchant', $payload['destinationAccount']);
        $this->assertSame(114729, $payload['destinationTag']);
        $this->assertSame(40.0, $payload['xrpAmount']);
        $this->assertSame('40', $payload['amountRequestedDisplay']);

        // The page: the same values the storefront renders, from the same view.
        $expected = $this->paymentPageView()->values($this->order(), $this->transaction('open', $intent), $intent, $this->salesChannelContext);
        $this->assertSame(1, $payload['page']['schema_version']);
        $this->assertSame(array_merge(['schema_version'], array_keys($expected)), array_keys($payload['page']));
        $this->assertSame($expected['amountDueDisplay'], $payload['page']['amountDueDisplay']);
        $this->assertSame($expected['paymentUri'], $payload['page']['paymentUri']);
        $this->assertSame('waiting', $payload['page']['state']);
    }

    public function testQuoteForTheSessionCustomerNeedsNoCode(): void
    {
        $this->salesChannelContext = $this->salesChannelContext($this->customer('the-customer'));
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote()), customerId: 'the-customer');
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);

        $payload = $this->quote([]);

        $this->assertSame('waiting', $payload['page']['state']);
    }

    public function testQuoteWithoutACodeOrASessionIsForbidden(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote()));

        $this->assertForbidden(fn () => $this->quote([]));
    }

    /**
     * A wrong code and another customer's order answer character for
     * character the same: neither tells whether the order exists.
     */
    public function testAWrongCodeAnswersLikeAnotherCustomersOrder(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote()), customerId: 'owner');
        $wrongCode = $this->assertForbidden(fn () => $this->quote(['deepLinkCode' => 'another-code']));

        $this->salesChannelContext = $this->salesChannelContext($this->customer('someone-else'));
        $otherCustomer = $this->assertForbidden(fn () => $this->quote([]));

        $this->assertSame($wrongCode->getErrorCode(), $otherCustomer->getErrorCode());
        $this->assertSame($wrongCode->getMessage(), $otherCustomer->getMessage());
        $this->assertSame($wrongCode->getStatusCode(), $otherCustomer->getStatusCode());
    }

    /**
     * An expired quote is handed out as the page shows it: state expired, the
     * stale amount still there for the "quote expired" block. A front end
     * builds the same page as the storefront, and refreshes from there.
     */
    public function testAnExpiredQuoteAnswersWithTheExpiredPage(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', $this->xrpQuote(expiry: time() - 1)));
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->never();

        $payload = $this->quote(['deepLinkCode' => 'the-code']);

        $this->assertSame('expired', $payload['page']['state']);
        $this->assertNull($payload['page']['secondsLeft']);
        $this->assertSame('40', $payload['page']['amountDueDisplay']);
    }

    /*
     * refresh: a current quote, re-quoting an expired one.
     */

    public function testRefreshReQuotesAnExpiredOrderAndAnswersTheFreshQuote(): void
    {
        $expired = $this->transaction('open', $this->xrpQuote(expiry: time() - 1));
        $this->givenAuthorisedOrder($expired);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->once()->with(Mockery::type(OrderEntity::class), $expired, $this->context);
        $fresh = $this->transaction('open', $this->xrpQuote(expiry: time() + 300));
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->once()->with(self::TX_ID, $this->context)->andReturn($fresh);

        $payload = $this->refresh(['deepLinkCode' => 'the-code']);

        $this->assertSame('waiting', $payload['page']['state']);
        $this->assertGreaterThan(200, $payload['page']['secondsLeft']);
        $this->assertSame(114729, $payload['destinationTag'], 'the tag stays');
    }

    /**
     * Not expired — waiting, or partly paid — nothing is re-quoted: a payment
     * on the tag is matched against the quote it was made for.
     */
    public function testRefreshLeavesAQuoteThatHasNotExpiredAlone(): void
    {
        $partial = $this->xrpQuote(expiry: time() - 60)->withFulfillment('HASH', 30.0, 'CTID');
        $this->givenAuthorisedOrder($this->transaction('paid_partially', $partial));
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->never();

        $payload = $this->refresh(['deepLinkCode' => 'the-code']);

        $this->assertSame('partial', $payload['page']['state']);
        $this->assertSame('10', $payload['page']['amountDueDisplay']);
    }

    public function testRefreshIsGuardedLikeQuote(): void
    {
        $this->givenAuthorisedOrder($this->transaction('cancelled', $this->xrpQuote(expiry: time() - 1)));
        $this->assertForbidden(fn () => $this->refresh([]));

        try {
            $this->refresh(['deepLinkCode' => 'the-code']);
            $this->fail('expected a 410 for a cancelled transaction');
        } catch (TransactionLifetimeException $exception) {
            $this->assertSame(410, $exception->getStatusCode());
        }
    }

    /**
     * The Store API document (src/Resources/Schema/StoreApi) describes every
     * route of this class and none that does not exist — a headless
     * developer reads that document first.
     */
    public function testTheOpenApiDocumentDescribesExactlyTheRoutesOfThisClass(): void
    {
        $schema = json_decode((string) file_get_contents(__DIR__ . '/../../../src/Resources/Schema/StoreApi/paths/ledger-direct-payment.json'), true, 512, \JSON_THROW_ON_ERROR);
        $documented = [];
        foreach ($schema['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $documented[] = strtoupper($method) . ' /store-api' . $path;
            }
        }

        $declared = [];
        foreach ((new \ReflectionClass(PaymentRoute::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(\Symfony\Component\Routing\Attribute\Route::class) as $attribute) {
                $route = $attribute->newInstance();
                // GET is what a front end calls; the POST twin of quote and check is Shopware's convention, not a second route.
                $declared[] = (in_array('GET', $route->getMethods(), true) ? 'GET' : 'POST') . ' ' . $route->getPath();
            }
        }

        sort($documented);
        sort($declared);
        $this->assertSame($declared, $documented);
    }

    /**
     * A short payment keeps the quote it was made for past the expiry — the
     * status stays partial — and a front end has to be able to show the
     * shortfall, so the quote is not refused on the raw timestamp.
     */
    public function testAPartiallyPaidQuoteIsHandedOutPastItsExpiry(): void
    {
        $partial = $this->xrpQuote(expiry: time() - 60)->withFulfillment('HASH', 30.0, 'CTID');
        $this->givenAuthorisedOrder($this->transaction('paid_partially', $partial));
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);

        $payload = $this->quote(['deepLinkCode' => 'the-code']);

        $this->assertSame('partial', $payload['page']['state']);
        $this->assertSame('10', $payload['page']['amountDueDisplay']);
    }

    /**
     * Closed by the merchant or paid: no payment instructions, whatever the
     * intent says — nothing sent now would be applied. `check` carries the
     * redirect for that case.
     */
    public function testAClosedTransactionGetsNoQuote(): void
    {
        $partial = $this->xrpQuote()->withFulfillment('HASH', 30.0, 'CTID');

        foreach (['cancelled' => $partial, 'paid' => $this->xrpQuote()->withFulfillment('HASH', 40.0, 'CTID')] as $state => $intent) {
            $this->setUp();
            $this->givenAuthorisedOrder($this->transaction($state, $intent));

            try {
                $this->quote(['deepLinkCode' => 'the-code']);
                $this->fail("expected a 410 for a {$state} transaction");
            } catch (TransactionLifetimeException $exception) {
                $this->assertSame(410, $exception->getStatusCode());
            }
        }
    }

    /**
     * The quote syncs first, as the storefront page does on render: a
     * payment that arrived since the last poll settles the order here, and
     * the customer is not handed instructions to pay a second time.
     */
    public function testAPaymentFoundOnTheLedgerSettlesTheOrderInsteadOfQuotingIt(): void
    {
        $before = $this->transaction('open', $this->xrpQuote());
        $settled = $this->xrpQuote()->withFulfillment('HASH', 40.0, 'CTID');
        $after = $this->transaction('paid', $settled);

        $this->givenAuthorisedOrder($before);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($before, $this->context, true)->andReturn($settled);
        $this->stateHandler->shouldReceive('paid')->once()->with(self::TX_ID, $this->context);
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->once()->andReturn($after);

        try {
            $this->quote(['deepLinkCode' => 'the-code']);
            $this->fail('expected a 410: the order is paid');
        } catch (TransactionLifetimeException $exception) {
            $this->assertSame(410, $exception->getStatusCode());
        }
    }

    public function testQuoteOfAnOrderNeverQuotedIsNotFound(): void
    {
        $this->givenAuthorisedOrder($this->transaction('open', null));

        $this->expectException(NoPaymentIntentException::class);

        $this->quote(['deepLinkCode' => 'the-code']);
    }

    public function testThePriceRouteIsGone(): void
    {
        $this->assertFalse(method_exists(PaymentRoute::class, 'price'), 'price was a placeholder answering {todo: implement}; quote covers it');
    }

    private function assertForbidden(callable $call): CartException
    {
        try {
            $call();
        } catch (CartException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return $exception;
        }

        $this->fail('expected a 403');
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function quote(array $query): array
    {
        return $this->route()->quote(self::ORDER_ID, new Request($query), $this->salesChannelContext)->getObject()->all();
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function refresh(array $query): array
    {
        return $this->route()->refresh(self::ORDER_ID, new Request($query), $this->salesChannelContext)->getObject()->all();
    }

    private function customer(string $id): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setId($id);

        return $customer;
    }

    private function paymentPageView(): PaymentPageView
    {
        return new PaymentPageView(new SettlementPolicy(), ConfigurationServiceMock::createInstance([]));
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
            new PaymentRedirect($this->router, new HeadlessTargets(ConfigurationServiceMock::createInstance([]))),
            $this->paymentPageView()
        );
    }

    private function givenAuthorisedOrder(OrderTransactionEntity $orderTransaction, string $deepLinkCode = 'the-code', ?string $customerId = null): void
    {
        $order = $this->order($deepLinkCode, $customerId);
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));

        $this->orderTransactionService->shouldReceive('getOrderWithTransactions')
            ->with(self::ORDER_ID, $this->context)
            ->andReturn($order);
    }

    private function order(string $deepLinkCode = 'the-code', ?string $customerId = null): OrderEntity
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setSymbol('€');

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setOrderNumber('10010');
        $order->setDeepLinkCode($deepLinkCode);
        $order->setSalesChannelId('sales-channel-id');
        $order->setCurrency($currency);

        if ($customerId !== null) {
            $orderCustomer = new OrderCustomerEntity();
            $orderCustomer->setId('order-customer-id');
            $orderCustomer->setCustomerId($customerId);
            $order->setOrderCustomer($orderCustomer);
        }

        return $order;
    }

    private function transaction(string $state, ?PaymentIntent $intent): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::TX_ID);
        $orderTransaction->setUniqueIdentifier(self::TX_ID);
        $orderTransaction->setStateMachineState($stateEntity);
        $orderTransaction->setAmount(new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

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
