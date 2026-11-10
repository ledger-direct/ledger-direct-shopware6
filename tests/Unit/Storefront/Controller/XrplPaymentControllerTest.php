<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Storefront\Controller;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Price\OraclesDisagreeException;
use Hardcastle\LedgerDirect\Exception\NoPaymentIntentException;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Hardcastle\LedgerDirect\Storefront\Controller\XrplPaymentController;
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
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The payment page's poll refuses with JSON, whatever the environment —
 * the same bodies Magento's and PrestaShop's status endpoints send.
 */
class XrplPaymentControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testAForbiddenPollAnswersWithJson(): void
    {
        $response = $this->checkPaymentThrowing(CartException::insufficientPermission());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'forbidden'], json_decode((string) $response->getContent(), true));
    }

    public function testAnOrderWithoutPaymentAnswersWithJson(): void
    {
        $response = $this->checkPaymentThrowing(new NoPaymentIntentException('order-id'));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'no_payment_intent'], json_decode((string) $response->getContent(), true));
    }

    public function testOtherCartErrorsAreNotSwallowed(): void
    {
        $other = CartException::orderNotFound('order-id');
        $this->assertNotSame(403, $other->getStatusCode());

        $this->expectExceptionObject($other);
        $this->checkPaymentThrowing($other);
    }

    /**
     * The "request a new amount" button when no price can be had: back to
     * the payment page, which is still expired and still has its button,
     * with a notice that says to try again in a moment. Nothing re-quoted,
     * nothing stored, no Shopware error page.
     */
    public function testARefreshWithoutAPriceGoesBackToTheExpiredPageWithANotice(): void
    {
        $orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $expired = PaymentIntent::quote(
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
            expiry: time() - 1,
        );

        $state = new StateMachineStateEntity();
        $state->setTechnicalName('open');
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('order-transaction-id');
        $orderTransaction->setStateMachineState($state);

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setDeepLinkCode('the-code');
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));

        $context = new Context(new SystemSource());
        /** @var SalesChannelContext&\Mockery\MockInterface $salesChannelContext */
        $salesChannelContext = Mockery::mock(SalesChannelContext::class);
        $salesChannelContext->shouldReceive('getContext')->andReturn($context);
        $salesChannelContext->shouldReceive('getCustomer')->andReturn(null);

        $orderTransactionService->shouldReceive('getOrderWithTransactions')->with('order-id', $context)->andReturn($order);
        $orderTransactionService->shouldReceive('readPaymentIntent')->with($orderTransaction)->andReturn($expired);
        $orderTransactionService->shouldReceive('prepareOrderTransaction')->once()
            ->andThrow(new OraclesDisagreeException('No usable price for XRP/EUR: the oracles disagree.'));

        $settlementPolicy = new SettlementPolicy();
        $controller = (new \ReflectionClass(XrplPaymentController::class))->newInstanceWithoutConstructor();
        foreach ([
            'orderTransactionService' => $orderTransactionService,
            'orderAccessGuard' => new OrderAccessGuard($orderTransactionService),
            'settlementPolicy' => $settlementPolicy,
            'paymentState' => new PaymentStateService(Mockery::mock(OrderTransactionStateHandler::class), $orderTransactionService, $settlementPolicy, new NullLogger()),
        ] as $property => $value) {
            (new \ReflectionProperty(XrplPaymentController::class, $property))->setValue($controller, $value);
        }

        $session = new Session(new MockArraySessionStorage());
        $request = new Request([], ['deepLinkCode' => 'the-code']);
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $router = Mockery::mock(RouterInterface::class);
        $router->shouldReceive('generate')
            ->with('frontend.checkout.ledger-direct.payment', ['orderId' => 'order-id', 'deepLinkCode' => 'the-code'], Mockery::any())
            ->andReturn('/ledger-direct/payment/order-id?deepLinkCode=the-code');
        $translator = Mockery::mock(TranslatorInterface::class);
        $translator->shouldReceive('trans')->with('LedgerDirect.paymentPage.priceUnavailable', [])->andReturn('The price is temporarily unavailable.');

        $container = new Container();
        $container->set('router', $router);
        $container->set('request_stack', $requestStack);
        $container->set('translator', $translator);
        $container->set('event_dispatcher', new EventDispatcher());
        $controller->setContainer($container);

        $response = $controller->refreshQuote($salesChannelContext, 'order-id', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/ledger-direct/payment/order-id?deepLinkCode=the-code', $response->getTargetUrl());
        $this->assertSame(
            ['The price is temporarily unavailable.'],
            $session->getFlashBag()->get(XrplPaymentController::FLASH_PRICE_UNAVAILABLE)
        );
    }

    /**
     * The notice belongs to the "quote expired" block, next to its button:
     * the page keeps its state, no new data-ld-state value. Both storefront
     * languages carry the text.
     */
    public function testThePaymentPageShowsTheNoticeInTheExpiredBlock(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../../../src/Resources/views/storefront/ledger-direct/payment.html.twig');

        $start = strpos($template, 'data-ld-block="expired"');
        $this->assertNotFalse($start);
        $expiredBlock = substr($template, $start, (int) strpos($template, '</form>', $start) - $start);

        $this->assertStringContainsString("app.flashes('" . XrplPaymentController::FLASH_PRICE_UNAVAILABLE . "')", $expiredBlock);
        $this->assertStringContainsString('refreshQuoteButton', substr($template, $start, (int) strpos($template, '</form>', $start) + 7 - $start));

        foreach (['en-GB', 'de-DE'] as $locale) {
            $snippets = json_decode((string) file_get_contents(__DIR__ . "/../../../../src/Resources/snippet/storefront/ledger-direct.{$locale}.json"), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertNotEmpty($snippets['LedgerDirect']['paymentPage']['priceUnavailable'] ?? null, $locale);
        }
    }

    private function checkPaymentThrowing(\Throwable $exception): \Symfony\Component\HttpFoundation\Response
    {
        $route = Mockery::mock(PaymentRoute::class);
        $route->shouldReceive('check')->once()->andThrow($exception);

        $controller = (new \ReflectionClass(XrplPaymentController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(XrplPaymentController::class, 'paymentRoute'))->setValue($controller, $route);

        /** @var SalesChannelContext&\Mockery\MockInterface $context */
        $context = Mockery::mock(SalesChannelContext::class);

        return $controller->checkPayment($context, 'order-id', new Request());
    }
}
