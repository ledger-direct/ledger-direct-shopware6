<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Storefront\Controller;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Presentation\PageLogo;
use Hardcastle\LedgerDirect\Presentation\PaymentPageView;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route(defaults: ['_routeScope' => ['storefront']])]
class XrplPaymentController extends StorefrontController
{
    private OrderTransactionService $orderTransactionService;

    private PaymentRoute $paymentRoute;

    private SettlementPolicy $settlementPolicy;

    private OrderAccessGuard $orderAccessGuard;

    private PaymentStateService $paymentState;

    private PaymentRedirect $paymentRedirect;

    private PaymentPageView $paymentPageView;

    private PageLogo $pageLogo;

    public function __construct(
        OrderTransactionService $orderTransactionService,
        PaymentRoute $paymentRoute,
        SettlementPolicy $settlementPolicy,
        OrderAccessGuard $orderAccessGuard,
        PaymentStateService $paymentState,
        PaymentRedirect $paymentRedirect,
        PaymentPageView $paymentPageView,
        PageLogo $pageLogo
    ) {
        $this->orderTransactionService = $orderTransactionService;
        $this->paymentRoute = $paymentRoute;
        $this->settlementPolicy = $settlementPolicy;
        $this->orderAccessGuard = $orderAccessGuard;
        $this->paymentState = $paymentState;
        $this->paymentRedirect = $paymentRedirect;
        $this->paymentPageView = $paymentPageView;
        $this->pageLogo = $pageLogo;
    }

    /**
     * No _loginRequired: the order's deepLinkCode (or the session customer
     * the order belongs to) authorises the request, see OrderAccessGuard.
     * With _loginRequired a guest — who has just placed the order — was sent
     * to a login page instead of the payment instructions.
     */
    #[Route(path: '/ledger-direct/payment/{orderId}', name: 'frontend.checkout.ledger-direct.payment', methods: ['GET', 'POST'], options: ['seo' => 'false'])]
    public function payment(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        $order = $this->orderAccessGuard->authorisedOrder($orderId, $request, $context);

        if (!$order) {
            // Same answer for "no such order" and "not yours": neither is told apart.
            return $this->redirectToRoute('frontend.account.order.page');
        }

        $orderTransaction = $order->getTransactions()->first();
        if (!$orderTransaction) {
            return $this->redirectToRoute('frontend.account.order.page');
        }

        $returnUrl = (string) $request->get('returnUrl');

        /*
         * Synced on render so the first page after the checkout shows the
         * current state; throttled so a reload costs no node request. A hit
         * moves the transaction's state right here, as the status endpoint
         * does.
         *
         * Something arriving on the tag is not the same as the order being
         * paid. A payment in the right asset but from the wrong issuer, or
         * one that falls short, is recorded on the intent but does not
         * settle it — sending the customer back to the shop at that point
         * leaves them with a partially paid order and no explanation, so
         * they stay here and the page tells them what is missing. Only a
         * transaction that is no longer open — settled, or closed by the
         * merchant — leaves the page.
         */
        $orderTransaction = $this->paymentState->syncAndApply($orderTransaction, $context->getContext(), throttled: true);

        if (!$this->paymentState->isOpen($orderTransaction)) {
            return new RedirectResponse($this->paymentRedirect->target($order, $returnUrl));
        }

        if (PaymentMethodInstaller::chainAndAssetFor($orderTransaction->getPaymentMethodId()) === null) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->renderPaymentPage($order, $orderTransaction, $returnUrl, $context);
    }

    #[Route(path: '/ledger-direct/payment/check/{orderId}', name: 'frontend.checkout.ledger-direct.check-payment', methods: ['GET', 'POST'], defaults: ['XmlHttpRequest' => true])]
    public function checkPayment(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        return $this->paymentRoute->check($orderId, $request, $context);
    }

    /**
     * A new quote for an expired one — the button under the "quote expired"
     * notice. Only while nothing has arrived: a payment on the tag, short or
     * in the wrong asset, is matched against the quote it was made for, and
     * a refresh would re-quote an order that is already partly paid. The
     * destination account and tag stay the same either way
     * (see OrderTransactionService::prepareOrderTransaction()).
     */
    #[Route(path: '/ledger-direct/payment/refresh/{orderId}', name: 'frontend.checkout.ledger-direct.refresh-quote', methods: ['POST'], options: ['seo' => 'false'])]
    public function refreshQuote(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        $order = $this->orderAccessGuard->authorisedOrder($orderId, $request, $context);

        if (!$order) {
            return $this->redirectToRoute('frontend.account.order.page');
        }

        $backToPaymentPage = $this->redirectToRoute('frontend.checkout.ledger-direct.payment', array_filter([
            'orderId' => $order->getId(),
            'deepLinkCode' => (string) $order->getDeepLinkCode(),
            'returnUrl' => (string) $request->get('returnUrl'),
        ]));

        $orderTransaction = $order->getTransactions()->first();
        $intent = $orderTransaction === null ? null : $this->orderTransactionService->readPaymentIntent($orderTransaction);

        if ($intent === null || !$this->paymentState->isOpen($orderTransaction)) {
            return $backToPaymentPage;
        }

        if (PaymentStatus::fromIntent($intent, $this->settlementPolicy)->state() !== PaymentStatus::EXPIRED) {
            return $backToPaymentPage;
        }

        $this->orderTransactionService->prepareOrderTransaction($order, $orderTransaction, $context->getContext());

        return $backToPaymentPage;
    }

    /**
     * The one payment page, for every chain and asset. Its values come from
     * PaymentPageView — the same set the Store API's `quote` hands out, so
     * the two cannot drift apart; this controller adds only what is the
     * storefront's: the logo markup and its own URLs.
     */
    private function renderPaymentPage(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        string $returnUrl,
        SalesChannelContext $context,
    ): Response
    {
        $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);

        if ($intent === null || ChainCatalog::forType($intent->type) === null) {
            $this->addFlash('danger', 'This order cannot be paid with LedgerDirect. Please contact support.');
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->renderStorefront(
            '@Storefront/storefront/ledger-direct/payment.html.twig',
            $this->paymentPageParameters($order, $orderTransaction, $intent, $returnUrl, $context)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPageParameters(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        PaymentIntent $intent,
        string $returnUrl,
        SalesChannelContext $context,
    ): array {
        $values = $this->paymentPageView->values($order, $orderTransaction, $intent, $context);
        $deepLinkCode = (string) $order->getDeepLinkCode();

        $routeParameters = array_filter([
            'orderId' => $order->getId(),
            'deepLinkCode' => $deepLinkCode,
            'returnUrl' => $returnUrl,
        ]);

        return $values + [
            'logo' => $this->pageLogo->forShop((string) $values['shopName'], $context->getContext()),
            'returnUrl' => $returnUrl,
            'deepLinkCode' => $deepLinkCode,
            'pageUrl' => $this->generateUrl('frontend.checkout.ledger-direct.payment', ['orderId' => $order->getId()]),
            'pollUrl' => $this->generateUrl('frontend.checkout.ledger-direct.check-payment', $routeParameters),
            'refreshUrl' => $this->generateUrl('frontend.checkout.ledger-direct.refresh-quote', ['orderId' => $order->getId()]),
        ];
    }
}
