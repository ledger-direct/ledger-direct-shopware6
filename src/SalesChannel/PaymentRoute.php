<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\SalesChannel;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Exception\NoPaymentIntentException;
use Hardcastle\LedgerDirect\Exception\TransactionLifetimeException;
use Hardcastle\LedgerDirect\Presentation\AmountFormatter;
use Hardcastle\LedgerDirect\Presentation\PaymentPageView;
use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class PaymentRoute
{
    private OrderTransactionService $orderTransactionService;

    private OrderAccessGuard $orderAccessGuard;

    private SettlementPolicy $settlementPolicy;

    private PaymentStateService $paymentState;

    private PaymentRedirect $paymentRedirect;

    private PaymentPageView $paymentPageView;

    public function __construct(
        OrderTransactionService $orderTransactionService,
        OrderAccessGuard $orderAccessGuard,
        SettlementPolicy $settlementPolicy,
        PaymentStateService $paymentState,
        PaymentRedirect $paymentRedirect,
        PaymentPageView $paymentPageView
    ) {
        $this->orderTransactionService = $orderTransactionService;
        $this->orderAccessGuard = $orderAccessGuard;
        $this->settlementPolicy = $settlementPolicy;
        $this->paymentState = $paymentState;
        $this->paymentRedirect = $paymentRedirect;
        $this->paymentPageView = $paymentPageView;
    }

    /**
     * The payment-status endpoint: "is this order paid?" while its customer
     * watches the payment page.
     *
     * The answer is the core's PaymentStatus payload (INVARIANTS.md,
     * "Payment status") — the same seven fields every LedgerDirect plugin
     * returns — plus the one field only this platform can build: `redirect`.
     * It is present as soon as the transaction is no longer open, whatever
     * closed it: settled on the ledger, or cancelled or paid by hand in the
     * administration, for which the contract has no state. Shopware adds
     * `apiAlias` of its own.
     *
     * While the transaction is open the ledger is synced (throttled, see
     * SyncThrottle) and a hit moves the transaction's state right here —
     * the customer need not come back over the returnUrl for the merchant
     * to see "paid" or "partially paid".
     *
     * Guarded by the order's deepLinkCode or the session customer, not by
     * _loginRequired — see OrderAccessGuard. 403 for anything else, without
     * telling whether the order exists; 404 for an order that was never
     * quoted for XRPL.
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

        $orderTransaction = $order->getTransactions()->first();

        if ($orderTransaction === null || $this->orderTransactionService->readPaymentIntent($orderTransaction) === null) {
            throw new NoPaymentIntentException($orderId);
        }

        $orderTransaction = $this->paymentState->syncAndApply($orderTransaction, $context->getContext(), throttled: true);

        /** @var \Hardcastle\LedgerDirect\Core\Payment\PaymentIntent $intent the record just checked, or as the sync left it */
        $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);

        $payload = PaymentStatus::fromIntent($intent, $this->settlementPolicy)->toArray();

        // The platform's additions to the contract. The hash of the payment on
        // record, once there is one: the success view shows it with the
        // explorer link, and a front end's order page can, too.
        if ($intent->hash !== null) {
            $payload['hash'] = $intent->hash;
        }

        if (!$this->paymentState->isOpen($orderTransaction)) {
            $payload['redirect'] = $this->paymentRedirect->target($order, $request->get('returnUrl'));
        }

        return new PaymentRouteResponse(new ArrayStruct($payload));
    }

    /**
     * The quote endpoint: what a payment page shows — for a headless front
     * end that renders its own.
     *
     * Guarded like `check`: the order's deepLinkCode or the session customer
     * (OrderAccessGuard), no login. A guest who has just placed the order
     * has the code; so has anyone opening the confirmation mail hours later.
     * Nothing becomes visible that the storefront's payment page does not
     * show for the same code. 403 for anything else, without telling whether
     * the order exists; 404 for an order never quoted with LedgerDirect; 410
     * for an order that no longer waits for a payment — `check` carries the
     * redirect for it. An expired quote is handed out as the page shows it,
     * with `page.state` `expired`: the amount is stale and the page says so;
     * `refresh` is the way to a current one.
     *
     * The payload has two layers. The flat fields are the Store API contract
     * from the first release and stay as they are. `page` is the complete
     * value set of the payment page — the same PaymentPageView the Twig page
     * renders, for every chain and asset — with its own `schema_version`.
     */
    #[Route(
        path: '/store-api/ledger-direct/payment/quote/{orderId}',
        name: 'store-api.ledger-direct.payment.quote',
        methods: ['GET', 'POST']
    )]
    public function quote(string $orderId, Request $request, SalesChannelContext $context): PaymentRouteResponse
    {
        [$order, $orderTransaction, $intent] = $this->openOrder($orderId, $request, $context);

        return new PaymentRouteResponse(new ArrayStruct($this->quotePayload($order, $orderTransaction, $intent, $context)));
    }

    /**
     * A current quote: the same answer as `quote`, after re-quoting an order
     * whose quote has expired — the button under the "quote expired" notice,
     * for a front end. Only while nothing has arrived: a payment on the tag,
     * short or in the wrong asset, is matched against the quote it was made
     * for, and a refresh would re-quote an order that is already partly
     * paid; such an order gets its current quote back unchanged. The
     * destination account and tag stay the same, unless the merchant changed
     * the receiving account in between (OrderTransactionService::prepareOrderTransaction()).
     */
    #[Route(
        path: '/store-api/ledger-direct/payment/refresh/{orderId}',
        name: 'store-api.ledger-direct.payment.refresh',
        methods: ['POST']
    )]
    public function refresh(string $orderId, Request $request, SalesChannelContext $context): PaymentRouteResponse
    {
        [$order, $orderTransaction, $intent] = $this->openOrder($orderId, $request, $context);

        if (PaymentStatus::fromIntent($intent, $this->settlementPolicy)->state() === PaymentStatus::EXPIRED) {
            $this->orderTransactionService->prepareOrderTransaction($order, $orderTransaction, $context->getContext());

            $orderTransaction = $this->orderTransactionService->getOrderTransactionById($orderTransaction->getId(), $context->getContext()) ?? $orderTransaction;
            $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction) ?? $intent;
        }

        return new PaymentRouteResponse(new ArrayStruct($this->quotePayload($order, $orderTransaction, $intent, $context)));
    }

    /**
     * The order behind `quote` and `refresh`, synced and still waiting for a
     * payment. Synced before answering, throttled, as the storefront page is
     * on render: a payment that reached the ledger since the last poll
     * settles the order right here, and a customer coming back to the page
     * is not handed instructions to pay a second time.
     *
     * @return array{0: OrderEntity, 1: OrderTransactionEntity, 2: PaymentIntent}
     */
    private function openOrder(string $orderId, Request $request, SalesChannelContext $context): array
    {
        $order = $this->orderAccessGuard->authorisedOrder($orderId, $request, $context);

        if ($order === null) {
            throw CartException::insufficientPermission();
        }

        $orderTransaction = $order->getTransactions()->first();

        if ($orderTransaction === null || $this->orderTransactionService->readPaymentIntent($orderTransaction) === null) {
            throw new NoPaymentIntentException($orderId);
        }

        $orderTransaction = $this->paymentState->syncAndApply($orderTransaction, $context->getContext(), throttled: true);

        /** @var PaymentIntent $intent the record as the sync left it */
        $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);

        if (ChainCatalog::forType($intent->type) === null) {
            throw new NoPaymentIntentException($orderId);
        }

        /*
         * No payment instructions for an order that no longer waits for a
         * payment — settled, or cancelled or paid by hand in the
         * administration: nothing sent now would be applied to it. The
         * storefront page redirects in that case; a front end polls `check`,
         * whose `redirect` says where to go.
         */
        if (!$this->paymentState->isOpen($orderTransaction)) {
            throw new TransactionLifetimeException('This order no longer waits for a payment');
        }

        return [$order, $orderTransaction, $intent];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotePayload(OrderEntity $order, OrderTransactionEntity $orderTransaction, PaymentIntent $intent, SalesChannelContext $context): array
    {
        return [
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'currencyCode' => $intent->quoteCurrency,
            'currencySymbol' => $order->getCurrency()?->getSymbol(),
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
            'page' => ['schema_version' => PaymentPageView::SCHEMA_VERSION]
                + $this->paymentPageView->values($order, $orderTransaction, $intent, $context),
        ];
    }
}
