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
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
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
 * The status, quote and refresh routes for an order that has had more than
 * one payment attempt: the customer changed the payment method after the
 * order, Shopware cancelled the transaction it had and created a new one.
 *
 * The routes took the order's oldest transaction. After a change that is the
 * cancelled one, so the new attempt could neither be shown nor polled, and
 * every answer said "leave". They follow the current transaction now, and
 * each test here is one of the cases that rule has:
 *
 *   - ours → ours (XRP → USDC): the new attempt is answered;
 *   - another method → ours: the order is ours now, not "never quoted";
 *   - ours → another method: nothing to pay here any more, the answer stays
 *     "leave" and nothing is synced or set on a transaction that is not ours;
 *   - a page still showing a replaced attempt names it on its poll and is
 *     sent on, instead of reporting on the new attempt under the old one's
 *     address, tag and amount.
 *
 * The order transaction service and the state handler are strict mocks: a
 * sync or a state change that no test announces fails it.
 */
class PaymentRouteCurrentTransactionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORDER_ID = 'order-id';

    private const USDC = ['currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    /** The order page and the payment page, as a router would build them: the route and what is on it. */
    private const ORDER_PAGE = 'frontend.account.order.single.page?deepLinkCode=the-code';

    private const PAYMENT_PAGE = 'frontend.checkout.ledger-direct.payment?orderId=order-id&deepLinkCode=the-code';

    /** @var OrderTransactionService&Mockery\MockInterface */
    private OrderTransactionService $orderTransactionService;

    /** @var OrderTransactionStateHandler&Mockery\MockInterface */
    private OrderTransactionStateHandler $stateHandler;

    private Context $context;

    /** @var SalesChannelContext&Mockery\MockInterface */
    private SalesChannelContext $salesChannelContext;

    private OrderEntity $order;

    /** @var array<string, string> the plugin configuration; an external front end's templates where a test has one */
    private array $configuration = [];

    protected function setUp(): void
    {
        /** @var OrderTransactionService&Mockery\MockInterface $orderTransactionService */
        $orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $this->orderTransactionService = $orderTransactionService;
        /** @var OrderTransactionStateHandler&Mockery\MockInterface $stateHandler */
        $stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        $this->stateHandler = $stateHandler;
        $this->context = new Context(new SystemSource());

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-id');
        $salesChannel->setName('Shop');

        /** @var SalesChannelContext&Mockery\MockInterface $salesChannelContext */
        $salesChannelContext = Mockery::mock(SalesChannelContext::class);
        $salesChannelContext->shouldReceive('getContext')->andReturn($this->context);
        $salesChannelContext->shouldReceive('getCustomer')->andReturn(null);
        $salesChannelContext->shouldReceive('getSalesChannel')->andReturn($salesChannel);
        $this->salesChannelContext = $salesChannelContext;
    }

    /*
     * Ours → ours.
     */

    public function testAfterAChangeBetweenOurMethodsTheStatusIsTheNewAttempts(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote());
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);

        $payload = $this->check();

        $this->assertSame('USDC', $payload['base_asset'], 'the attempt the customer changed to, not the cancelled first one');
        $this->assertSame('waiting', $payload['state']);
        $this->assertArrayNotHasKey('redirect', $payload, 'the new attempt waits for its payment; nothing says leave');
    }

    public function testAfterAChangeBetweenOurMethodsTheQuoteIsTheNewAttempts(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote());
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);

        $quote = $this->quote();

        $this->assertSame('USDC', $quote['page']['assetLabel']);
        $this->assertSame(220002, $quote['destinationTag'], 'the new attempt has a tag of its own');
        $this->assertSame('40', $quote['amountRequestedDisplay']);
    }

    public function testARefreshQuotesTheNewAttemptAgainNotTheCancelledOne(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(expiry: time() - 60));
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote(expiry: time() - 60));
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->once()->with($this->order, $current, $this->context);
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->once()->with('tx-usdc', $this->context)->andReturn($current);

        $quote = $this->route()->refresh(self::ORDER_ID, $this->request(), $this->salesChannelContext)->getObject()->getVars();

        $this->assertSame('USDC', $quote['page']['assetLabel']);
    }

    /*
     * Another method → ours.
     */

    public function testAfterAChangeFromAnotherMethodTheOrderIsOurs(): void
    {
        $foreign = $this->transaction('tx-invoice', 'cancelled', null);
        $current = $this->transaction('tx-xrp', 'open', $this->xrpQuote());
        $this->givenOrder($foreign, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->twice()->with($current, $this->context, true)->andReturn(null);

        $payload = $this->check();
        $quote = $this->quote();

        $this->assertSame('XRP', $payload['base_asset'], 'was 404 "never quoted": the first transaction has no record');
        $this->assertSame('waiting', $payload['state']);
        $this->assertArrayNotHasKey('redirect', $payload);
        $this->assertSame(110001, $quote['destinationTag']);
    }

    /*
     * Ours → another method.
     */

    /**
     * 200 with `redirect`, not 404: the page that polls treats every other
     * status as "nothing new" and would keep the cancelled attempt's address,
     * tag and amount on the screen for good. The target is the order page —
     * never the finalize route with the token the request carries, which is
     * the cancelled transaction's, and never the payment page, which has
     * nothing to show. And the transaction that is current is another
     * handler's: no sync, no state.
     */
    public function testAfterAChangeToAnotherMethodTheStatusSaysLeaveAndTouchesNothing(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $foreign = $this->transaction('tx-prepayment', 'open', null);
        $this->givenOrder($cancelled, $foreign);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();

        $payload = $this->check(['returnUrl' => 'https://shop.example/payment/finalize-transaction?_sw_payment_token=' . self::liveToken()]);

        $this->assertSame(self::ORDER_PAGE, $payload['redirect']);
        $this->assertSame('XRP', $payload['base_asset'], 'the contract\'s fields, from the record the order still has');
        $this->assertSame('waiting', $payload['state']);
        $this->assertSame(
            ['schema_version', 'state', 'base_asset', 'amount_requested', 'amount_paid', 'shortfall', 'seconds_left', 'redirect'],
            array_keys($payload),
            'the poll contract as it is: no new field'
        );
    }

    /**
     * 410 as the route documents for an order that no longer waits, not 404
     * "never quoted"; and no quote is made for a transaction that is not ours.
     */
    public function testAfterAChangeToAnotherMethodThereIsNoQuoteAndNoRefresh(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(expiry: time() - 60));
        $foreign = $this->transaction('tx-prepayment', 'open', null);
        $this->givenOrder($cancelled, $foreign);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->never();

        foreach (['quote', 'refresh'] as $route) {
            try {
                $this->route()->{$route}(self::ORDER_ID, $this->request(), $this->salesChannelContext);
                $this->fail("expected a 410 from {$route}");
            } catch (TransactionLifetimeException $exception) {
                $this->assertSame(410, $exception->getStatusCode(), $route);
            }
        }
    }

    public function testAnOrderNeverQuotedStaysNotFoundHoweverManyTransactionsItHas(): void
    {
        $this->givenOrder($this->transaction('tx-invoice', 'cancelled', null), $this->transaction('tx-prepayment', 'open', null));

        foreach (['check', 'quote', 'refresh'] as $route) {
            try {
                $this->route()->{$route}(self::ORDER_ID, $this->request(), $this->salesChannelContext);
                $this->fail("expected a 404 from {$route}");
            } catch (NoPaymentIntentException $exception) {
                $this->assertSame(404, $exception->getStatusCode(), $route);
            }
        }
    }

    /*
     * A page that still shows a replaced attempt.
     */

    /**
     * The page of the first attempt polls on after the change. Its answer is
     * `redirect` to the order's payment page, which shows the current
     * attempt: built from the order alone, without the returnUrl the request
     * carries (its token is the cancelled transaction's) and without the
     * transaction it names. The fields are the current attempt's, so nothing
     * is reported that the order does not have; and nothing is synced for a
     * request that only has to leave.
     */
    public function testAPollNamingAReplacedAttemptIsSentToTheOrdersPaymentPage(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote());
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();

        $payload = $this->check([
            'orderTransactionId' => 'tx-xrp',
            'returnUrl' => 'https://shop.example/payment/finalize-transaction?_sw_payment_token=' . self::liveToken(),
        ]);

        $this->assertSame(self::PAYMENT_PAGE, $payload['redirect']);
        $this->assertSame('USDC', $payload['base_asset']);
        $this->assertSame('waiting', $payload['state']);
    }

    public function testAPollNamingTheCurrentAttemptIsAnsweredAsAlways(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote());
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);

        $payload = $this->check(['orderTransactionId' => 'tx-usdc']);

        $this->assertSame('USDC', $payload['base_asset']);
        $this->assertArrayNotHasKey('redirect', $payload);
    }

    /**
     * The current attempt is paid while the old page is still open: its poll
     * may say so, it is true of the order, and then sends the page on.
     */
    public function testAPollNamingAReplacedAttemptReportsWhatTheCurrentOneHas(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $paid = $this->transaction('tx-usdc', 'paid', $this->usdcQuote()->withFulfillment('HASH', self::USDC + ['value' => '40'], 'C000000000000001'));
        $this->givenOrder($cancelled, $paid);

        $payload = $this->check(['orderTransactionId' => 'tx-xrp']);

        $this->assertSame('settled', $payload['state']);
        $this->assertSame('HASH', $payload['hash']);
        $this->assertSame(self::PAYMENT_PAGE, $payload['redirect']);
    }

    public function testAPollNamingAReplacedAttemptAfterAChangeToAnotherMethodGoesToTheOrderPage(): void
    {
        $this->givenOrder($this->transaction('tx-xrp', 'cancelled', $this->xrpQuote()), $this->transaction('tx-prepayment', 'open', null));

        $payload = $this->check(['orderTransactionId' => 'tx-xrp']);

        $this->assertSame(self::ORDER_PAGE, $payload['redirect'], 'the payment page would have nothing to show');
    }

    /**
     * A front end that asks for the quote of an attempt by its id gets it
     * while it is the current one, and 410 afterwards: nothing of a replaced
     * attempt is handed out as if it could still be paid.
     */
    public function testAQuoteNamingAReplacedAttemptIsGone(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote());
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);

        $this->assertSame('USDC', $this->quote(['orderTransactionId' => 'tx-usdc'])['page']['assetLabel']);

        try {
            $this->quote(['orderTransactionId' => 'tx-xrp']);
            $this->fail('expected a 410');
        } catch (TransactionLifetimeException $exception) {
            $this->assertSame(410, $exception->getStatusCode());
        }
    }

    /*
     * What an external front end needs to do the same: the quote names its
     * transaction.
     *
     * The storefront's page knows the transaction it was rendered for and
     * names it on its poll. A front end that builds its own page from `quote`
     * had no way to learn it, so its page of a replaced attempt went on
     * polling without an id and was answered about the new attempt, under the
     * old one's address, tag and amount. The quote hands the id out, the front
     * end sends it back, and `check` tells it when that attempt is over.
     */

    /**
     * Next to `orderId`, among the route's own fields, and only there: `page`
     * is what a payment page shows, the same set the storefront renders from,
     * and this is a parameter of the routes.
     */
    public function testTheQuoteNamesTheTransactionItIsFor(): void
    {
        $only = $this->transaction('tx-xrp', 'open', $this->xrpQuote());
        $this->givenOrder($only);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($only, $this->context, true)->andReturn(null);

        $quote = $this->quote();

        $this->assertSame('tx-xrp', $quote['orderTransactionId'] ?? null);
        $this->assertArrayNotHasKey('orderTransactionId', $quote['page']);
        $this->assertSame(
            ['orderId', 'orderNumber', 'orderTransactionId'],
            array_slice(array_keys($quote), 0, 3),
            'with the fields that say what the quote is of'
        );
    }

    public function testAfterAChangeBetweenOurMethodsTheQuoteNamesTheNewAttempt(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote());
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);

        $this->assertSame('tx-usdc', $this->quote()['orderTransactionId'] ?? null, 'the attempt that is quoted, not the first one of the order');
    }

    /**
     * `refresh` answers what `quote` answers, for the transaction it quoted again.
     */
    public function testARefreshNamesTheTransactionItQuotedAgain(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(expiry: time() - 60));
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote(expiry: time() - 60));
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->andReturn(null);
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->once()->with($this->order, $current, $this->context);
        $this->orderTransactionService->shouldReceive('getOrderTransactionById')->once()->with('tx-usdc', $this->context)->andReturn($current);

        $quote = $this->route()->refresh(self::ORDER_ID, $this->request(), $this->salesChannelContext)->getObject()->getVars();

        $this->assertSame('tx-usdc', $quote['orderTransactionId'] ?? null);
    }

    /**
     * The whole round of a front end's own page. It asks for the quote and
     * keeps the id. The customer changes the payment method elsewhere. The
     * page's next status request, with the id it has, is told to leave for the
     * order's payment page. There it asks for the quote again, without the old
     * id, and gets the new attempt with a new id, which `check` accepts.
     */
    public function testACheckNamingTheIdOfTheFirstQuoteIsToldToLeaveAndTheNextQuoteNamesTheNewAttempt(): void
    {
        $first = $this->transaction('tx-xrp', 'open', $this->xrpQuote());
        $this->givenOrder($first);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($first, $this->context, true)->andReturn(null);
        $idOfTheFirstQuote = $this->quote()['orderTransactionId'] ?? null;
        $this->assertSame('tx-xrp', $idOfTheFirstQuote);

        // The customer changes to USDC: Shopware cancels the transaction and creates a new one.
        $this->setUp();
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote());
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote());
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->twice()->with($current, $this->context, true)->andReturn(null);

        $status = $this->check(['orderTransactionId' => $idOfTheFirstQuote]);
        $this->assertSame(self::PAYMENT_PAGE, $status['redirect'] ?? null, 'the page of the first quote is told to leave');

        $next = $this->quote();
        $this->assertSame('tx-usdc', $next['orderTransactionId'] ?? null);
        $this->assertSame('USDC', $next['page']['assetLabel']);
        $this->assertArrayNotHasKey('redirect', $this->check(['orderTransactionId' => $next['orderTransactionId']]), 'and with the new id it waits for its payment');
    }

    /**
     * On a sales channel with an external front end the page to leave for is
     * that front end's own, from the merchant's template with an empty
     * returnUrl; the storefront's would be a page it does not serve.
     */
    public function testOnAnExternalFrontEndAReplacedAttemptIsSentToItsOwnPaymentPage(): void
    {
        $this->configuration = ['LedgerDirect.config.headlessPaymentPageUrl' => 'https://frontend.example/pay/{orderId}?code={deepLinkCode}&return={returnUrl}'];
        $this->givenOrder($this->transaction('tx-xrp', 'cancelled', $this->xrpQuote()), $this->transaction('tx-usdc', 'open', $this->usdcQuote()));

        $status = $this->check(['orderTransactionId' => 'tx-xrp']);

        $this->assertSame('https://frontend.example/pay/order-id?code=the-code&return=', $status['redirect'] ?? null);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function check(array $query = []): array
    {
        return $this->route()->check(self::ORDER_ID, $this->request($query), $this->salesChannelContext)->getObject()->getVars();
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function quote(array $query = []): array
    {
        return $this->route()->quote(self::ORDER_ID, $this->request($query), $this->salesChannelContext)->getObject()->getVars();
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query = []): Request
    {
        return new Request(['deepLinkCode' => 'the-code'] + $query);
    }

    private function route(): PaymentRoute
    {
        $settlementPolicy = new SettlementPolicy();

        /** @var RouterInterface&Mockery\MockInterface $router */
        $router = Mockery::mock(RouterInterface::class);
        $router->shouldReceive('generate')->andReturnUsing(
            static fn (string $name, array $parameters = []): string => $name . '?' . http_build_query($parameters)
        );

        return new PaymentRoute(
            $this->orderTransactionService,
            new OrderAccessGuard($this->orderTransactionService),
            $settlementPolicy,
            new PaymentStateService($this->stateHandler, $this->orderTransactionService, $settlementPolicy, new NullLogger()),
            new PaymentRedirect($router, new HeadlessTargets(ConfigurationServiceMock::createInstance($this->configuration))),
            new PaymentPageView($settlementPolicy, ConfigurationServiceMock::createInstance([]))
        );
    }

    /** The order with these transactions, oldest first, as getOrderWithTransactions() loads it. */
    private function givenOrder(OrderTransactionEntity ...$transactions): void
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setSymbol('€');

        $this->order = new OrderEntity();
        $this->order->setId(self::ORDER_ID);
        $this->order->setOrderNumber('10010');
        $this->order->setDeepLinkCode('the-code');
        $this->order->setSalesChannelId('sales-channel-id');
        $this->order->setCurrency($currency);
        $this->order->setTransactions(new OrderTransactionCollection($transactions));

        $this->orderTransactionService->shouldReceive('getOrderWithTransactions')
            ->with(self::ORDER_ID, $this->context)
            ->andReturn($this->order);
    }

    /**
     * @param PaymentIntent|null $intent null: a transaction of a payment method that is not ours
     */
    private function transaction(string $id, string $state, ?PaymentIntent $intent): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId($id);
        $orderTransaction->setUniqueIdentifier($id);
        $orderTransaction->setStateMachineState($stateEntity);
        $orderTransaction->setAmount(new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        $this->orderTransactionService->shouldReceive('readPaymentIntent')->with($orderTransaction)->andReturn($intent);

        return $orderTransaction;
    }

    private function xrpQuote(?int $expiry = null): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 40.0,
            destinationAccount: 'rMerchant', destinationTag: 110001, expiry: $expiry ?? time() + 300,
        );
    }

    private function usdcQuote(?int $expiry = null): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'usdc-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'USDC', quoteCurrency: 'USD',
            pairing: 'USDC/USD', exchangeRate: 1.0, amountRequested: self::USDC + ['value' => '40'],
            destinationAccount: 'rMerchant', destinationTag: 220002, expiry: $expiry ?? time() + 300,
        );
    }

    /** A payment token as a returnUrl carries it, not yet expired: the route must not send anyone there. */
    private static function liveToken(): string
    {
        $segment = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $segment(['alg' => 'HS256']) . '.' . $segment(['exp' => time() + 1000]) . '.signature';
    }
}
