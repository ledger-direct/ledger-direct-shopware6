<?php declare(strict_types=1);

namespace LedgerDirect\Storefront\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use LedgerDirect\Core\Content\Xrpl\SalesChannel\PaymentRoute;
use LedgerDirect\Core\Content\Xrpl\SalesChannel\PaymentRouteResponse;
use LedgerDirect\Provider\CryptoPriceProviderInterface;
use LedgerDirect\Service\ConfigurationService;
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
     * @Route("/ledger-direct/payment/{orderId}", name="frontend.checkout.ledger-direct.payment", methods={"GET", "POST"}, options={"seo"="false"})
     */
    public function payment(SalesChannelContext $context, string $orderId, Request $request)
    {
        //TODO: Check if orderTransaction ist still valid

        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());
        $orderTransaction = $order->getTransactions()->first();

        $returnUrl = $request->get('returnUrl');

        $tx = $this->orderTransactionService->syncOrderTransactionWithXrpl($orderTransaction, $context->getContext());
        if ($tx) {
            return new RedirectResponse($request->get('returnUrl'));
        }

        $customFields = $orderTransaction->getCustomFields();
        if (!isset($customFields['xrpl'])) {
            // TODO: Throw new Exception, this TA cannot be paid in XRP
        }

        // https://goqr.me/api/doc/create-qr-code/

        return $this->renderStorefront('@Storefront/storefront/ledger-direct/payment.html.twig', [
            'orderId' => $orderId,
            'orderNumber' => $order->getOrderNumber(),
            'destinationAccount' => $customFields['xrpl']['destination_account'],
            'destinationTag' => $customFields['xrpl']['destination_tag'],
            'xrpAmount' => $customFields['xrpl']['amount'],
            'currencyCode' => str_replace('XRP/','', $customFields['xrpl']['pairing']),
            'exchangeRate' => $customFields['xrpl']['exchange_rate'],
            'returnUrl' => $returnUrl,
            'showNoTransactionFoundError' => true,
        ]);
    }

    /**
     * @Route("/ledger-direct/payment/check/{orderId}", name="frontend.checkout.ledger-direct.check-payment", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function checkPayment(SalesChannelContext $context,  string $orderId, Request $request): Response
    {
        return $this->paymentRoute->check($orderId, $context);
    }

}