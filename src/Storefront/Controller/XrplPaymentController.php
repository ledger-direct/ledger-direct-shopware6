<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Storefront\Controller;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Presentation\AmountFormatter;
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

    public function __construct(
        OrderTransactionService $orderTransactionService,
        PaymentRoute $paymentRoute,
        SettlementPolicy $settlementPolicy,
        OrderAccessGuard $orderAccessGuard,
        PaymentStateService $paymentState,
        PaymentRedirect $paymentRedirect
    ) {
        $this->orderTransactionService = $orderTransactionService;
        $this->paymentRoute = $paymentRoute;
        $this->settlementPolicy = $settlementPolicy;
        $this->orderAccessGuard = $orderAccessGuard;
        $this->paymentState = $paymentState;
        $this->paymentRedirect = $paymentRedirect;
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

        return match ($orderTransaction->getPaymentMethodId()) {
            PaymentMethodInstaller::XRP_PAYMENT_ID => $this->renderXrpPaymentPage($order, $orderTransaction, $returnUrl),
            PaymentMethodInstaller::RLUSD_PAYMENT_ID => $this->renderStablecoinPaymentPage($order, $orderTransaction, 'rlusd', $returnUrl),
            PaymentMethodInstaller::USDC_PAYMENT_ID => $this->renderStablecoinPaymentPage($order, $orderTransaction, 'usdc', $returnUrl),
            default => $this->redirectToRoute('frontend.checkout.cart.page'),
        };
    }

    #[Route(path: '/ledger-direct/payment/check/{orderId}', name: 'frontend.checkout.ledger-direct.check-payment', methods: ['GET', 'POST'], defaults: ['XmlHttpRequest' => true])]
    public function checkPayment(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        return $this->paymentRoute->check($orderId, $request, $context);
    }

    /**
     * Renders the payment page for XRP payments.
     */
    private function renderXrpPaymentPage(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        string $returnUrl,
    ): Response
    {
        $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);

        if ($intent === null) {
            // Redirect to the checkout page with an error message stating that this message cannot be paid in XRP
            $this->addFlash('danger', 'This order cannot be paid with XRP. Please contact support.');
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->renderStorefront(
            '@Storefront/storefront/ledger-direct/payment.html.twig',
            $this->paymentPageParameters($order, $orderTransaction, $intent, 'xrp', $returnUrl)
        );
    }

    private function renderStablecoinPaymentPage(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        string $type,
        string $returnUrl,
    ): Response
    {
        $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);

        if ($intent === null) {
            $this->addFlash('danger', 'This order cannot be paid with ' . strtoupper($type) . '. Please contact support.');
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->renderStorefront(
            '@Storefront/storefront/ledger-direct/payment.html.twig',
            $this->paymentPageParameters($order, $orderTransaction, $intent, $type, $returnUrl)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPageParameters(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        PaymentIntent $intent,
        string $mode,
        string $returnUrl,
    ): array {
        $amountPaid = $intent->amountPaidValue();

        return [
            'mode' => $mode,
            'amountPaid' => $amountPaid,
            'shortfall' => $amountPaid === null ? null : $this->settlementPolicy->shortfall($intent),
            'wrongToken' => $this->settlementPolicy->isWrongAsset($intent),
            // The one string the page asks the customer for; see AmountFormatter.
            'amountRequestedDisplay' => AmountFormatter::amountRequested($intent),
            'exchangeRateDisplay' => AmountFormatter::rate($intent->exchangeRate),
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'total' => $orderTransaction->getAmount()->getTotalPrice(),
            'currencyCode' => $intent->quoteCurrency,
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'network' => $intent->network,
            'destinationAccount' => $intent->destinationAccount,
            'destinationTag' => $intent->destinationTag,
            'amountRequested' => $intent->amountRequested,
            'exchangeRate' => $intent->exchangeRate,
            'returnUrl' => $returnUrl,
            // Handed to the script so the status poll carries the order secret too.
            'deepLinkCode' => (string) $order->getDeepLinkCode(),
            'showNoTransactionFoundError' => true,
            'paymentPageTitle' => 'Pay with ' . strtoupper($mode) . ' on XRPL ' . $intent->network,
        ];
    }
}
