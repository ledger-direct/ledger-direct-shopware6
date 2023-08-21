<?php declare(strict_types=1);

namespace LedgerDirect\Storefront\Controller;

use LedgerDirect\Installer\PaymentMethodInstaller;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use LedgerDirect\Core\Content\Xrpl\SalesChannel\PaymentRoute;
use LedgerDirect\Service\OrderTransactionService;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class XrpPaymentController extends StorefrontController
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

    /**
     * @Route("/ledger-direct/payment/{orderId}", name="frontend.checkout.ledger-direct.payment", methods={"GET", "POST"}, defaults={"_loginRequired"=true}, options={"seo"="false"})
     */
    public function payment(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        //TODO: Check if orderTransaction ist still valid

        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());
        $orderTransaction = $order->getTransactions()->first();

        $returnUrl = $request->get('returnUrl');

        $tx = $this->orderTransactionService->syncOrderTransactionWithXrpl($orderTransaction, $context->getContext());
        if ($tx) {
            return new RedirectResponse($request->get('returnUrl'));
        }

        return match ($orderTransaction->getPaymentMethodId()) {
            PaymentMethodInstaller::XRP_PAYMENT_ID => $this->renderXrpPaymentPage($order, $orderTransaction, $returnUrl),
            PaymentMethodInstaller::TOKEN_PAYMENT_ID => $this->renderTokenPaymentPage($order, $orderTransaction, $returnUrl),
        };
    }

    /**
     * @Route("/ledger-direct/payment/check/{orderId}", name="frontend.checkout.ledger-direct.check-payment", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true, "_loginRequired"=true})
     */
    public function checkPayment(SalesChannelContext $context,  string $orderId, Request $request): Response
    {
        return $this->paymentRoute->check($orderId, $context);
    }

    private function renderXrpPaymentPage(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        string $returnUrl,
    ): Response
    {
        $customFields = $orderTransaction->getCustomFields();

        if (!isset($customFields['xrpl'])) {
            // TODO: Throw new Exception, this TA cannot be paid in XRP
        }

        return $this->renderStorefront('@Storefront/storefront/ledger-direct/payment.html.twig', [
            'mode' => 'xrp',
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'price' => $orderTransaction->getAmount()->getTotalPrice(),
            'currencyCode' => str_replace('XRP/','', $customFields['xrpl']['pairing']),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'network' => $customFields['xrpl']['network'],
            'destinationAccount' => $customFields['xrpl']['destination_account'],
            'destinationTag' => $customFields['xrpl']['destination_tag'],
            'xrpAmount' => $customFields['xrpl']['amount_requested'],
            'exchangeRate' => $customFields['xrpl']['exchange_rate'],
            'returnUrl' => $returnUrl,
            'showNoTransactionFoundError' => true,
        ]);
    }

    private function renderTokenPaymentPage(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        string $returnUrl,
    ): Response
    {
        $customFields = $orderTransaction->getCustomFields();

        if (!isset($customFields['xrpl'])) {
            // TODO: Throw new Exception, this TA cannot be paid in XRP
        }

        return $this->renderStorefront('@Storefront/storefront/ledger-direct/payment.html.twig', [
            'mode' => 'token',
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'price' => $orderTransaction->getAmount()->getTotalPrice(),
            'currencyCode' => $order->getCurrency()->getIsoCode(),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'network' => $customFields['xrpl']['network'],
            'destinationAccount' => $customFields['xrpl']['destination_account'],
            'destinationTag' => $customFields['xrpl']['destination_tag'],
            'tokenAmount' => $customFields['xrpl']['value'],
            'issuer' => $customFields['xrpl']['issuer'],
            'returnUrl' => $returnUrl,
            'showNoTransactionFoundError' => true,
        ]);
    }

}