<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Storefront\Controller;

use Hardcastle\LedgerDirect\Core\Content\Xrpl\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;


#[Route(defaults: ['_routeScope' => ['storefront']])]
class XrplPaymentController extends StorefrontController
{
    private OrderTransactionService $orderTransactionService;

    private PaymentRoute $paymentRoute;

    private RouterInterface $router;

    public function __construct(
        OrderTransactionService $orderTransactionService,
        PaymentRoute $paymentRoute,
        RouterInterface $router
    ) {
        $this->orderTransactionService = $orderTransactionService;
        $this->paymentRoute = $paymentRoute;
        $this->router = $router;
    }

    #[Route(path: '/ledger-direct/payment/{orderId}', name: 'frontend.checkout.ledger-direct.payment', methods: ['GET', 'POST'], defaults: ['_loginRequired' => true], options: ['seo' => 'false'])]
    public function payment(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        //TODO: Check if orderTransaction ist still valid

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

        $returnUrl = $request->get('returnUrl');
        if (!$returnUrl) {
            $returnUrl = $orderTransaction->getReturnUrl();
        }

        $tx = $this->orderTransactionService->syncOrderTransactionWithXrpl($orderTransaction, $context->getContext());
        if ($tx) {
            return new RedirectResponse($request->get('returnUrl'));
        }

        return match ($orderTransaction->getPaymentMethodId()) {
            PaymentMethodInstaller::XRP_PAYMENT_ID => $this->renderXrpPaymentPage($order, $orderTransaction, $returnUrl),
            PaymentMethodInstaller::RLUSD_PAYMENT_ID => $this->renderStablecoinPaymentPage($order, $orderTransaction, 'rlusd', $returnUrl),
            PaymentMethodInstaller::USDC_PAYMENT_ID => $this->renderStablecoinPaymentPage($order, $orderTransaction, 'usdc', $returnUrl),
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
        $customFields = $orderTransaction->getCustomFields();

        if (!isset($customFields['ledger_direct'])) {
            // Redirect to the checkout page with an error message stating that this message cannot be paid in XRP
            $this->addFlash('danger', 'This order cannot be paid with XRP. Please contact support.');
            return $this->redirectToRoute('frontend.checkout.cart.page');

        }

        return $this->renderStorefront('@Storefront/storefront/ledger-direct/payment.html.twig', [
            'mode' => 'xrp',
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'total' => $orderTransaction->getAmount()->getTotalPrice(),
            'currencyCode' => str_replace('XRP/','', $customFields['ledger_direct']['pairing']),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'network' => $customFields['ledger_direct']['network'],
            'destinationAccount' => $customFields['ledger_direct']['destination_account'],
            'destinationTag' => $customFields['ledger_direct']['destination_tag'],
            'amountRequested' => $customFields['ledger_direct']['amount_requested'],
            'exchangeRate' => $customFields['ledger_direct']['exchange_rate'],
            'returnUrl' => $returnUrl,
            'showNoTransactionFoundError' => true,
            'paymentPageTitle' => 'Pay with XRP on XRPL ' . $customFields['ledger_direct']['network']
        ]);
    }

    /**
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     * @param string $type
     * @param string $returnUrl
     * @return Response
     */
    private function renderStablecoinPaymentPage(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        string $type,
        string $returnUrl,
    ): Response
    {
        $customFields = $orderTransaction->getCustomFields();

        if (!isset($customFields['ledger_direct'])) {

        }

        return $this->renderStorefront('@Storefront/storefront/ledger-direct/payment.html.twig', [
            'mode' => $type,
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'total' => $orderTransaction->getAmount()->getTotalPrice(),
            'currencyCode' => $order->getCurrency()->getIsoCode(),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'network' => $customFields['ledger_direct']['network'],
            'destinationAccount' => $customFields['ledger_direct']['destination_account'],
            'destinationTag' => $customFields['ledger_direct']['destination_tag'],
            'amountRequested' => $customFields['ledger_direct']['amount_requested'],
            'exchangeRate' => $customFields['ledger_direct']['exchange_rate'],
            'returnUrl' => $returnUrl,
            'showNoTransactionFoundError' => true,
            'paymentPageTitle' => 'Pay with ' . strtoupper($type) . ' on XRPL ' . $customFields['ledger_direct']['network'],
        ]);
    }
}