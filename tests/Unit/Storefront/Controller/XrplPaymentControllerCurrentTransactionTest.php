<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Storefront\Controller;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Presentation\PageLogo;
use Hardcastle\LedgerDirect\Presentation\PaymentPageView;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Service\HeadlessTargets;
use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Hardcastle\LedgerDirect\Storefront\Controller\XrplPaymentController;
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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * The payment page and its "request a new amount" form for an order that has
 * had more than one payment attempt. The page took the order's oldest
 * transaction: after the customer changed the payment method that is the
 * cancelled one, and the page of the new attempt went straight to the return
 * URL instead of showing what to pay. It follows the current transaction now.
 *
 * What the page would render is caught where the controller hands it to
 * Twig, so a test can say which attempt's asset, tag and poll are on it.
 * Everything else is real, and the order transaction service and the state
 * handler are strict mocks: a sync or a state change no test announces fails it.
 */
class XrplPaymentControllerCurrentTransactionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const USDC = ['currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    private const ORDER_PAGE = 'frontend.account.order.single.page?deepLinkCode=the-code';

    private const PAYMENT_PAGE = 'frontend.checkout.ledger-direct.payment?orderId=order-id&deepLinkCode=the-code';

    /** @var OrderTransactionService&Mockery\MockInterface */
    private OrderTransactionService $orderTransactionService;

    private Context $context;

    /** @var SalesChannelContext&Mockery\MockInterface */
    private SalesChannelContext $salesChannelContext;

    private OrderEntity $order;

    /** @var array<string, mixed>|null what the page was rendered with, null while nothing was rendered */
    private ?array $rendered = null;

    protected function setUp(): void
    {
        /** @var OrderTransactionService&Mockery\MockInterface $orderTransactionService */
        $orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $this->orderTransactionService = $orderTransactionService;
        $this->context = new Context(new SystemSource());
        $this->rendered = null;

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
     * One attempt: as it always was.
     */

    public function testAnOrderWithOneOpenAttemptShowsIt(): void
    {
        $only = $this->transaction('tx-xrp', 'open', $this->xrpQuote(), PaymentMethodInstaller::XRP_PAYMENT_ID);
        $this->givenOrder($only);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($only, $this->context, true)->andReturn(null);

        $this->page();

        $this->assertSame('XRP', $this->rendered['assetLabel'] ?? null);
        $this->assertSame(110001, $this->rendered['destinationTag'] ?? null);
    }

    public function testAnOrderWhoseOnlyAttemptIsClosedLeavesAsBefore(): void
    {
        $this->givenOrder($this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(), PaymentMethodInstaller::XRP_PAYMENT_ID));

        $response = $this->page();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(self::ORDER_PAGE, $response->getTargetUrl());
        $this->assertNull($this->rendered);
    }

    public function testAnOrderThatWasNeverOursGoesToTheCartAsBefore(): void
    {
        $this->givenOrder($this->transaction('tx-invoice', 'open', null, 'another-payment-method'));

        $response = $this->page();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('frontend.checkout.cart.page?', $response->getTargetUrl());
    }

    /*
     * Ours → ours, and another method → ours: the new attempt is shown.
     */

    /**
     * The request names no transaction, as a bookmark or a mail link does.
     * The page it gets is the new attempt's, and its poll and both its forms
     * name that transaction from then on.
     */
    public function testAfterAChangeBetweenOurMethodsThePageShowsTheNewAttempt(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(), PaymentMethodInstaller::XRP_PAYMENT_ID);
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote(), PaymentMethodInstaller::USDC_PAYMENT_ID);
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);

        $response = $this->page(['returnUrl' => '/payment/finalize-transaction?_sw_payment_token=t']);

        $this->assertSame(200, $response->getStatusCode(), 'was a redirect to the return URL: the oldest transaction is cancelled');
        $this->assertSame('USDC', $this->rendered['assetLabel'] ?? null);
        $this->assertSame(220002, $this->rendered['destinationTag'] ?? null, 'the new attempt\'s own tag');
        $this->assertSame('40', $this->rendered['amountDueDisplay'] ?? null);
        $this->assertSame('tx-usdc', $this->rendered['orderTransactionId'] ?? null, 'for the hidden field of the two forms');
        $this->assertSame(
            'frontend.checkout.ledger-direct.check-payment?orderId=order-id&deepLinkCode=the-code&returnUrl=' . rawurlencode('/payment/finalize-transaction?_sw_payment_token=t') . '&orderTransactionId=tx-usdc',
            str_replace('+', '%20', (string) ($this->rendered['pollUrl'] ?? ''))
        );
    }

    public function testAfterAChangeFromAnotherMethodThePageShowsOurAttempt(): void
    {
        $foreign = $this->transaction('tx-invoice', 'cancelled', null, 'another-payment-method');
        $current = $this->transaction('tx-xrp', 'open', $this->xrpQuote(), PaymentMethodInstaller::XRP_PAYMENT_ID);
        $this->givenOrder($foreign, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);

        $this->page();

        $this->assertSame('XRP', $this->rendered['assetLabel'] ?? null);
        $this->assertSame('tx-xrp', $this->rendered['orderTransactionId'] ?? null);
    }

    /*
     * Ours → another method: the page leaves, and touches nothing.
     */

    /**
     * To the order page. Not to the cart, where a transaction of another
     * method used to end; not over the request's returnUrl, whose token is
     * the cancelled transaction's; and without a sync or a state change on a
     * transaction that belongs to another payment handler.
     */
    public function testAfterAChangeToAnotherMethodThePageLeavesForTheOrderPage(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(), PaymentMethodInstaller::XRP_PAYMENT_ID);
        $foreign = $this->transaction('tx-prepayment', 'open', null, 'another-payment-method');
        $this->givenOrder($cancelled, $foreign);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();

        foreach ([[], ['orderTransactionId' => 'tx-xrp'], ['returnUrl' => '/payment/finalize-transaction?_sw_payment_token=' . self::liveToken()]] as $query) {
            $response = $this->page($query);

            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertSame(self::ORDER_PAGE, $response->getTargetUrl(), (string) json_encode(array_keys($query)));
            $this->assertNull($this->rendered);
        }
    }

    /*
     * A page that still shows a replaced attempt.
     */

    /**
     * Reloaded, or its "check payment now" form posted without JavaScript:
     * the request names the cancelled attempt. It is sent to the order's
     * payment page, built from the order alone, where the current attempt is
     * shown. The cancelled attempt's address, tag and amount are not rendered
     * a second time, and its token is not passed on.
     */
    public function testAPageNamingAReplacedAttemptIsSentToTheOrdersPaymentPage(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(), PaymentMethodInstaller::XRP_PAYMENT_ID);
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote(), PaymentMethodInstaller::USDC_PAYMENT_ID);
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->never();

        $reload = $this->page(['orderTransactionId' => 'tx-xrp', 'returnUrl' => '/payment/finalize-transaction?_sw_payment_token=' . self::liveToken()]);
        $form = $this->controller()->payment($this->salesChannelContext, 'order-id', new Request([], ['deepLinkCode' => 'the-code', 'orderTransactionId' => 'tx-xrp']));

        foreach ([$reload, $form] as $response) {
            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertSame(self::PAYMENT_PAGE, $response->getTargetUrl());
        }
        $this->assertNull($this->rendered);
    }

    public function testAPageNamingTheCurrentAttemptIsShown(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(), PaymentMethodInstaller::XRP_PAYMENT_ID);
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote(), PaymentMethodInstaller::USDC_PAYMENT_ID);
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('syncOrderTransaction')->once()->with($current, $this->context, true)->andReturn(null);

        $this->page(['orderTransactionId' => 'tx-usdc']);

        $this->assertSame('USDC', $this->rendered['assetLabel'] ?? null);
    }

    /*
     * "Request a new amount".
     */

    public function testARefreshQuotesTheCurrentAttemptAgain(): void
    {
        $cancelled = $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(expiry: time() - 60), PaymentMethodInstaller::XRP_PAYMENT_ID);
        $current = $this->transaction('tx-usdc', 'open', $this->usdcQuote(expiry: time() - 60), PaymentMethodInstaller::USDC_PAYMENT_ID);
        $this->givenOrder($cancelled, $current);
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->once()->with($this->order, $current, $this->context);

        $response = $this->refresh(['deepLinkCode' => 'the-code', 'orderTransactionId' => 'tx-usdc']);

        $this->assertSame(self::PAYMENT_PAGE . '&orderTransactionId=tx-usdc', $response->getTargetUrl(), 'back to the page, which goes on naming the attempt');
    }

    /**
     * The form of a replaced attempt's page, and any refresh once the
     * customer has changed to another method: nothing is quoted again. Back
     * to the page with what the form named; the page knows where to send it.
     */
    public function testARefreshQuotesNothingForAnAttemptThatIsNotCurrent(): void
    {
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->never();

        $this->givenOrder(
            $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(expiry: time() - 60), PaymentMethodInstaller::XRP_PAYMENT_ID),
            $this->transaction('tx-usdc', 'open', $this->usdcQuote(expiry: time() - 60), PaymentMethodInstaller::USDC_PAYMENT_ID)
        );
        $replaced = $this->refresh(['deepLinkCode' => 'the-code', 'orderTransactionId' => 'tx-xrp']);

        $this->setUp();
        $this->orderTransactionService->shouldReceive('prepareOrderTransaction')->never();
        $this->givenOrder(
            $this->transaction('tx-xrp', 'cancelled', $this->xrpQuote(expiry: time() - 60), PaymentMethodInstaller::XRP_PAYMENT_ID),
            $this->transaction('tx-prepayment', 'open', null, 'another-payment-method')
        );
        $movedOn = $this->refresh(['deepLinkCode' => 'the-code']);

        $this->assertSame(self::PAYMENT_PAGE . '&orderTransactionId=tx-xrp', $replaced->getTargetUrl());
        $this->assertSame(self::PAYMENT_PAGE, $movedOn->getTargetUrl());
    }

    /**
     * Both forms of the page post the transaction it was rendered for, next
     * to the code and the return URL: the refresh, and "check payment now",
     * which is a plain form post to the page when JavaScript is off.
     */
    public function testBothFormsOfThePageNameItsTransaction(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../src/Resources/views/storefront/ledger-direct/payment.html.twig');

        preg_match_all('#<form\b.*?</form>#s', $template, $forms);

        $this->assertCount(2, $forms[0]);
        foreach ($forms[0] as $form) {
            $this->assertStringContainsString('<input type="hidden" name="orderTransactionId" value="{{ orderTransactionId }}">', $form);
            $this->assertStringContainsString('name="deepLinkCode"', $form);
        }
        $this->assertStringContainsString('data-ld-poll-url="{{ pollUrl }}"', $template, 'the poll URL is the controller\'s, the script builds none');
    }

    /**
     * @param array<string, string> $query
     */
    private function page(array $query = []): Response
    {
        return $this->controller()->payment($this->salesChannelContext, 'order-id', new Request(['deepLinkCode' => 'the-code'] + $query));
    }

    /**
     * @param array<string, string> $form
     */
    private function refresh(array $form): RedirectResponse
    {
        $response = $this->controller()->refreshQuote($this->salesChannelContext, 'order-id', new Request([], $form));
        $this->assertInstanceOf(RedirectResponse::class, $response);

        return $response;
    }

    private function controller(): XrplPaymentController
    {
        $settlementPolicy = new SettlementPolicy();
        $configuration = ConfigurationServiceMock::createInstance([]);

        /** @var RouterInterface&Mockery\MockInterface $router */
        $router = Mockery::mock(RouterInterface::class);
        $router->shouldReceive('generate')->andReturnUsing(
            static fn (string $name, array $parameters = []): string => $name . '?' . http_build_query($parameters)
        );

        /** @var OrderTransactionStateHandler&Mockery\MockInterface $stateHandler no state is set in any of these cases */
        $stateHandler = Mockery::mock(OrderTransactionStateHandler::class);
        /** @var EntityRepository&Mockery\MockInterface $mediaRepository */
        $mediaRepository = Mockery::mock(EntityRepository::class);

        $paymentState = new PaymentStateService($stateHandler, $this->orderTransactionService, $settlementPolicy, new NullLogger());
        $paymentRedirect = new PaymentRedirect($router, new HeadlessTargets($configuration));
        $paymentPageView = new PaymentPageView($settlementPolicy, $configuration);
        $guard = new OrderAccessGuard($this->orderTransactionService);

        /** @var XrplPaymentController&Mockery\MockInterface $controller */
        $controller = Mockery::mock(XrplPaymentController::class, [
            $this->orderTransactionService,
            new PaymentRoute($this->orderTransactionService, $guard, $settlementPolicy, $paymentState, $paymentRedirect, $paymentPageView),
            $settlementPolicy,
            $guard,
            $paymentState,
            $paymentRedirect,
            $paymentPageView,
            new PageLogo($configuration, $mediaRepository),
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        // Where the controller hands the page to Twig: keep what it would render.
        $controller->shouldReceive('renderStorefront')->andReturnUsing(function (string $view, array $parameters): Response {
            $this->rendered = $parameters;

            return new Response('rendered');
        });

        $requestStack = new RequestStack();
        $requestStack->push(new Request());
        $container = new Container();
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);
        $container->set('event_dispatcher', new EventDispatcher());
        $controller->setContainer($container);

        return $controller;
    }

    /** The order with these transactions, oldest first, as getOrderWithTransactions() loads it. */
    private function givenOrder(OrderTransactionEntity ...$transactions): void
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setSymbol('€');

        $this->order = new OrderEntity();
        $this->order->setId('order-id');
        $this->order->setOrderNumber('10010');
        $this->order->setDeepLinkCode('the-code');
        $this->order->setSalesChannelId('sales-channel-id');
        $this->order->setCurrency($currency);
        $this->order->setTransactions(new OrderTransactionCollection($transactions));

        $this->orderTransactionService->shouldReceive('getOrderWithTransactions')->with('order-id', $this->context)->andReturn($this->order);
    }

    /**
     * @param PaymentIntent|null $intent null: a transaction of a payment method that is not ours
     */
    private function transaction(string $id, string $state, ?PaymentIntent $intent, string $paymentMethodId): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId($id);
        $orderTransaction->setUniqueIdentifier($id);
        $orderTransaction->setStateMachineState($stateEntity);
        $orderTransaction->setPaymentMethodId($paymentMethodId);
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

    /** A payment token as a returnUrl carries it, not yet expired: the page must not send anyone there. */
    private static function liveToken(): string
    {
        $segment = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

        return $segment(['alg' => 'HS256']) . '.' . $segment(['exp' => time() + 1000]) . '.signature';
    }
}
