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
    private ConfigurationService $configurationService;

    private CryptoPriceProviderInterface $priceProvider;

    private OrderTransactionService $orderTransactionService;

    private PaymentRoute $paymentRoute;

    public function __construct(
        ConfigurationService $configurationService,
        CryptoPriceProviderInterface $priceProvider,
        OrderTransactionService $orderTransactionService,
        PaymentRoute $paymentRoute
    ) {
        $this->configurationService = $configurationService;
        $this->priceProvider = $priceProvider;
        $this->orderTransactionService = $orderTransactionService;
        $this->paymentRoute = $paymentRoute;
    }

    /**
     * @Route("/xrpl-connector/payment/{orderId}", name="frontend.checkout.xrpl-connector.payment", methods={"GET", "POST"}, options={"seo"="false"})
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

        $destinationTag = $customFields['xrpl']['destination_tag'];

        $currentXrpAmount = $this->priceProvider->getCurrentPriceForOrder($order, $context->getContext());

        // https://goqr.me/api/doc/create-qr-code/

        return $this->renderStorefront('@Storefront/storefront/xrpl-connector/payment.html.twig', [
            'destinationAccount' => $this->configurationService->getDestinationAccount(),
            'destinationTag' => $destinationTag,
            'orderId' => $orderId,
            'orderNumber' => $order->getOrderNumber(),
            'returnUrl' => $returnUrl,
            'showNoTransactionFoundError' => true,
            'xrpAmount' => $currentXrpAmount
        ]);
    }

    /**
     * @Route("/xrpl-connector/payment/check/{orderId}", name="frontend.checkout.xrpl-connector.check-payment", methods={"GET", "POST"}, defaults={"XmlHttpRequest"=true})
     */
    public function checkPayment(SalesChannelContext $context,  string $orderId, Request $request): Response
    {
        return $this->paymentRoute->check($orderId, $context);
    }

}