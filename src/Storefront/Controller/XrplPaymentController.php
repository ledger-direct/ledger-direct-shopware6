<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Storefront\Controller;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
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

    public function __construct(
        OrderTransactionService $orderTransactionService,
        PaymentRoute $paymentRoute
    ) {
        $this->orderTransactionService = $orderTransactionService;
        $this->paymentRoute = $paymentRoute;
    }

    #[Route(path: '/ledger-direct/payment/{orderId}', name: 'frontend.checkout.ledger-direct.payment', methods: ['GET', 'POST'], defaults: ['_loginRequired' => true], options: ['seo' => 'false'])]
    public function payment(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());

        if (!$order) {
            $this->addFlash('danger', 'Die Bestellung wurde nicht gefunden.');
            return $this->redirectToRoute('frontend.account.home.page');
        }

        $orderTransaction = $order->getTransactions()->first();
        if (!$orderTransaction) {
            $this->addFlash('danger', 'Die Bestellung wurde nicht gefunden.');
            return $this->redirectToRoute('frontend.account.home.page');
        }

        $returnUrl = (string) $request->get('returnUrl');

        $fulfilledIntent = $this->orderTransactionService->syncOrderTransactionWithXrpl(
            $orderTransaction,
            $context->getContext()
        );
        if ($fulfilledIntent !== null) {
            return new RedirectResponse($request->get('returnUrl'));
        }

        return match ($orderTransaction->getPaymentMethodId()) {
            PaymentMethodInstaller::XRP_PAYMENT_ID => $this->renderXrpPaymentPage($order, $orderTransaction, $returnUrl),
            PaymentMethodInstaller::RLUSD_PAYMENT_ID => $this->renderStablecoinPaymentPage($order, $orderTransaction, 'rlusd', $returnUrl),
            PaymentMethodInstaller::USDC_PAYMENT_ID => $this->renderStablecoinPaymentPage($order, $orderTransaction, 'usdc', $returnUrl),
            default => $this->redirectToRoute('frontend.checkout.cart.page'),
        };
    }

    #[Route(path: '/ledger-direct/payment/check/{orderId}', name: 'frontend.checkout.ledger-direct.check-payment', methods: ['GET', 'POST'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => true])]
    public function checkPayment(SalesChannelContext $context,  string $orderId, Request $request): Response
    {
        return $this->paymentRoute->check($orderId, $context);
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
     * The template variables are unchanged; they are just read off the
     * PaymentIntent now instead of the raw customFields array.
     *
     * @return array<string, mixed>
     */
    private function paymentPageParameters(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        PaymentIntent $intent,
        string $mode,
        string $returnUrl,
    ): array {
        return [
            'mode' => $mode,
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
            'showNoTransactionFoundError' => true,
            'paymentPageTitle' => 'Pay with ' . strtoupper($mode) . ' on XRPL ' . $intent->network,
        ];
    }
}
