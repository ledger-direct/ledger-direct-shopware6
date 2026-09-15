<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\SalesChannel;

use Hardcastle\LedgerDirect\Exception\TransactionLifetimeException;
use Hardcastle\LedgerDirect\Presentation\AmountFormatter;
use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use RuntimeException;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class PaymentRoute
{
    private OrderTransactionService $orderTransactionService;

    private OrderAccessGuard $orderAccessGuard;

    public function __construct(
        OrderTransactionService $orderTransactionService,
        OrderAccessGuard $orderAccessGuard
    ) {
        $this->orderTransactionService = $orderTransactionService;
        $this->orderAccessGuard = $orderAccessGuard;
    }

    /**
     * Guarded by the order's deepLinkCode or the session customer, not by
     * _loginRequired — see OrderAccessGuard. 403 for anything else, without
     * telling whether the order exists.
     */
    #[Route(
        path: '/store-api/ledger-direct/payment/check/{orderId}',
        name: 'store-api.ledger-direct.payment.check',
        methods: ['GET', 'POST']
    )]
    public function check(string $orderId, Request $request, SalesChannelContext $context): PaymentRouteResponse
    {
        $order = $this->orderAccessGuard->authorisedOrder($orderId, $request, $context);

        if ($order === null) {
            throw CartException::insufficientPermission();
        }

        $response = new ArrayStruct(['success' => false]);

        $orderTransaction = $order->getTransactions()->first();

        if ($orderTransaction !== null) {
            $intent = $this->orderTransactionService->syncOrderTransactionWithXrpl(
                $orderTransaction,
                $context->getContext()
            );

            if ($intent !== null) {
                $response = new ArrayStruct([
                    'success' => true,
                    'hash' => $intent->hash,
                    'ctid' => $intent->ctid,
                ]);
            }
        }

        return new PaymentRouteResponse($response);
    }

    #[Route(
        path: '/store-api/ledger-direct/payment/price/{orderId}',
        name: 'store-api.ledger-direct.payment.price',
        methods: ['GET', 'POST']
    )]
    public function price(string $orderId, SalesChannelContext $context): PaymentRouteResponse
    {
        $customer = $context->getCustomer();

        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());

        if (!$order || !$customer || $customer->getId() !== $order->getOrderCustomer()?->getCustomerId()) {
            throw CartException::customerNotLoggedIn();
        }

        return new PaymentRouteResponse(new ArrayStruct(['todo' => 'implement']));
    }

    #[Route(
        path: '/store-api/ledger-direct/payment/quote/{orderId}',
        name: 'store-api.ledger-direct.payment.quote',
        methods: ['GET', 'POST']
    )]
    public function quote(string $orderId, SalesChannelContext $context): PaymentRouteResponse
    {
        $customer = $context->getCustomer();

        if (!$customer) {
            throw CartException::customerNotLoggedIn();
        }

        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());

        if (!$order || $customer->getId() !== $order->getOrderCustomer()?->getCustomerId()) {
            throw CartException::insufficientPermission();
        }

        /** @var OrderTransactionEntity $orderTransaction */
        $orderTransaction = $order->getTransactions()->first();

        $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);

        if ($intent === null) {
            throw new RuntimeException('LedgerDirect: order ' . $orderId . ' has no XRPL payment attached.');
        }

        /*
         * Now that the core writes a real expiry (it is derived from the
         * configured quote validity), the lifetime check that used to sit
         * here commented out can finally do its job: past the expiry the
         * quoted exchange rate is stale and the amount must not be handed
         * out again as if it were current.
         */
        if ($intent->expiry !== null && $intent->expiry < time()) {
            throw new TransactionLifetimeException('This transaction is not valid anymore');
        }

        return new PaymentRouteResponse(new ArrayStruct([
            'orderId' => $orderId,
            'orderNumber' => $order->getOrderNumber(),
            'currencyCode' => $intent->quoteCurrency,
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'price' => $orderTransaction->getAmount()->getTotalPrice(),
            'network' => $intent->network,
            'destinationAccount' => $intent->destinationAccount,
            'destinationTag' => $intent->destinationTag,
            'xrpAmount' => $intent->amountRequested,
            'exchangeRate' => $intent->exchangeRate,
            /*
             * The raw values above stay as they are — they are an existing
             * store-api contract. These are the same numbers as the payment
             * page renders them, so a headless client shows the customer the
             * amount the shop actually expects.
             */
            'amountRequestedDisplay' => AmountFormatter::amountRequested($intent),
            'exchangeRateDisplay' => AmountFormatter::rate($intent->exchangeRate),
            'showNoTransactionFoundError' => true,
        ]));
    }
}
