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

    private string $kernelSecret;

    public function __construct(
        OrderTransactionService $orderTransactionService,
        PaymentRoute $paymentRoute,
        RouterInterface $router,
        string $kernelSecret
    ) {
        $this->orderTransactionService = $orderTransactionService;
        $this->paymentRoute = $paymentRoute;
        $this->router = $router;
        $this->kernelSecret = $kernelSecret;
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
            $returnUrl = $this->router->generate('frontend.checkout.finish.page', ['orderId' => $orderId]);
        }

        $tx = $this->orderTransactionService->syncOrderTransactionWithXrpl($orderTransaction, $context->getContext());
        if ($tx) {
            return new RedirectResponse($returnUrl);
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

    #[Route(path: '/ledger-direct/payment/xrpl-intent/{orderId}', name: 'frontend.checkout.ledger-direct.xrpl-intent', methods: ['GET'], defaults: ['XmlHttpRequest' => true, '_loginRequired' => true])]
    public function xrplIntent(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());

        if (!$order) {
            return $this->json(['error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $order->getTransactions()->first();
        if (!$orderTransaction) {
            return $this->json(['error' => 'Transaction not found'], Response::HTTP_NOT_FOUND);
        }

        $customFields = $orderTransaction->getCustomFields() ?? [];
        if (!$customFields['ledger_direct'] ) {
            return $this->json(['error' => 'Payment data unavailable'], Response::HTTP_BAD_REQUEST);
        }

        $intent = $customFields['ledger_direct'];

        // Build canonical intent payload (server is source of truth)
        $payload = [
            'intentVersion'     => '1',
            'orderId'           => $order->getId(),
            'network'           => $intent['network'],
            'destination'       => $intent['destination_account'],
            'destinationTag'    => $intent['destination_tag'],
            'amount_requested'  => $intent['amount_requested'],
            'decimals'          => 6,
            'memo'              => sprintf('Order %s', $order->getOrderNumber()),
            'nonce'             => bin2hex(random_bytes(8)),
            'expiresAt'         => time() + 10 * 60, // 10 minutes
        ];

        // Add server-side signature to help the client detect tampering when passing data around.
        // The backend remains the final verifier before marking an order as paid.
        $payload['signature'] = $this->signIntent($payload);

        return $this->json($payload);
    }

    /**
     * Computes an HMAC signature for the intent over a stable set of fields.
     * This is optional integrity metadata for the client; the server should still
     * verify the on-chain transaction against authoritative values.
     */
    private function signIntent(array $intent): string
    {
        // Select critical fields in a deterministic order
        $data = [
            'intentVersion'  => $intent['intentVersion'] ?? '1',
            'orderId'        => $intent['orderId'] ?? '',
            'network'        => $intent['network'] ?? '',
            'destination'    => $intent['destination'] ?? '',
            'destinationTag' => (string)($intent['destinationTag'] ?? ''),
            'amount'         => (string)($intent['amount'] ?? ''),
            'currency'       => $intent['currency'] ?? '',
            'issuer'         => (string)($intent['issuer'] ?? ''),
            'decimals'       => (string)($intent['decimals'] ?? ''),
            'memo'           => (string)($intent['memo'] ?? ''),
            'nonce'          => (string)($intent['nonce'] ?? ''),
            'expiresAt'      => (string)($intent['expiresAt'] ?? ''),
        ];

        $secret = $this->kernelSecret ?: 'ledgerdirect-fallback-secret';
        $message = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($message === false) {
            // Fallback: serialize to a simple key=value list if JSON encoding ever fails
            $message = implode('&', array_map(static function ($k, $v) { return $k . '=' . $v; }, array_keys($data), array_values($data)));
        }

        return hash_hmac('sha256', $message, $secret);
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

        $intent = $customFields['ledger_direct'];

        return $this->renderStorefront('@Storefront/storefront/ledger-direct/payment.html.twig', [
            'mode' => 'xrp',
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'total' => $orderTransaction->getAmount()->getTotalPrice(),
            'currencyCode' => str_replace('XRP/','', $intent['pairing']),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'network' => $intent['network'],
            'destinationAccount' => $intent['destination_account'],
            'destinationTag' => $intent['destination_tag'],
            'amountRequested' => $intent['amount_requested'],
            'exchangeRate' => $intent['exchange_rate'],
            'returnUrl' => $returnUrl,
            'showNoTransactionFoundError' => true,
            'paymentPageTitle' => 'Pay with XRP on XRPL ' . $intent['network']
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

        $intent = $customFields['ledger_direct'];

        return $this->renderStorefront('@Storefront/storefront/ledger-direct/payment.html.twig', [
            'mode' => $type,
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'total' => $orderTransaction->getAmount()->getTotalPrice(),
            'currencyCode' => $order->getCurrency()->getIsoCode(),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'network' => $intent ['network'],
            'destinationAccount' => $intent ['destination_account'],
            'destinationTag' => $intent ['destination_tag'],
            'amountRequested' => $intent ['amount_requested'],
            'exchangeRate' => $intent ['exchange_rate'],
            'returnUrl' => $returnUrl,
            'showNoTransactionFoundError' => true,
            'paymentPageTitle' => 'Pay with ' . strtoupper($type) . ' on XRPL ' . $intent ['network'],
        ]);
    }
}