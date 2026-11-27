<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Presentation\ReceivedPaymentsPresenter;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use InvalidArgumentException;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ledger payments an order received, for the administration's order
 * detail.
 *
 * Every order transaction with a LedgerDirect payment record is listed, a
 * cancelled or failed one too, and not only the one Shopware shows as
 * current: each has its own tag, and a customer can pay an attempt the shop
 * has given up on. An order without such a transaction answers an empty
 * list, so the administration can ask for any order.
 *
 * The list is read from the local transaction table alone: like the payment
 * card's route (PaymentInfoController) it starts no ledger sync. Whoever
 * may read the order may read this (`order:read`). 404 for an unknown order.
 *
 * `refresh` is the card's button: it fetches what is on the ledger for the
 * order's receiving accounts first, then answers the same list. Once a
 * transaction is no longer open nothing but the scheduled task syncs its
 * account, so a second payment on a paid order, or a payment on a cancelled
 * attempt, would otherwise show up minutes later.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReceivedPaymentsController extends AbstractController
{
    public function __construct(
        private readonly OrderTransactionService $orderTransactionService,
        private readonly SettlementPolicy $settlementPolicy,
        private readonly PaymentStateService $paymentState,
    ) {
    }

    #[Route(
        path: '/api/_action/ledger-direct/order/{orderId}/payments',
        name: 'api.action.ledger-direct.order.payments',
        defaults: ['_acl' => ['order:read']],
        methods: ['GET']
    )]
    public function payments(string $orderId, Context $context): JsonResponse
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context);

        if ($order === null) {
            return new JsonResponse(['error' => 'order_not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['transactions' => $this->transactions($order)]);
    }

    /**
     * The same list, after asking the ledger.
     *
     * Synced is the receiving account of every LedgerDirect transaction of
     * the order, on its chain and network, as its payment record names them:
     * an attempt quoted before the merchant changed the receiving account is
     * still paid to the old one. Each account once, however many of the
     * order's transactions share it, and throttled like every sync a request
     * can trigger.
     *
     * A transaction that is still open then gets its state from what the
     * table holds, as the payment page's poll gives it: otherwise the list
     * would show a payment while the payment card above goes on saying
     * "waiting" until the next run of the scheduled task. Sync once and match
     * afterwards, as that task does (matchAndApply(), not syncAndApply(),
     * which would go to the node per transaction). A transaction that is no
     * longer open keeps its state; its rows are all that changes.
     *
     * The button decides nothing, it fetches what the ledger says, so
     * `order:read` is enough to press it. The state a payment brings about is
     * therefore written as the system, with the context the scheduled task
     * writes it with, and not as the user: it is the ledger's finding and not
     * an edit of theirs. The user may lack the right to change a transaction,
     * and the state history should not name them for a payment that arrived.
     *
     * The answer says in `sync` whether the list is current: `synced`,
     * `throttled` (an account was asked within the last seconds; what is
     * stored is that recent) or `failed` (a node gave no usable answer; the
     * list is what was stored before and may lack payments). One account
     * failing makes the whole answer `failed`.
     */
    #[Route(
        path: '/api/_action/ledger-direct/order/{orderId}/payments/refresh',
        name: 'api.action.ledger-direct.order.payments.refresh',
        defaults: ['_acl' => ['order:read']],
        methods: ['POST']
    )]
    public function refresh(string $orderId, Context $context): JsonResponse
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context);

        if ($order === null) {
            return new JsonResponse(['error' => 'order_not_found'], Response::HTTP_NOT_FOUND);
        }

        $outcomes = [];
        $ours = [];

        foreach ($order->getTransactions() ?? [] as $orderTransaction) {
            try {
                $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);
            } catch (InvalidArgumentException) {
                // No account can be read from it; the list names it as unreadable.
                continue;
            }

            if ($intent === null) {
                continue;
            }

            $ours[] = $orderTransaction;
            $account = $intent->chain . '|' . $intent->network . '|' . $intent->destinationAccount;
            $outcomes[$account] ??= $this->orderTransactionService->syncLedger($intent->chain, $intent->destinationAccount, $intent->network, throttled: true);
        }

        $stateChanged = false;
        $system = Context::createCLIContext();

        foreach ($ours as $orderTransaction) {
            $before = PaymentStateService::stateOf($orderTransaction);
            $after = $this->paymentState->matchAndApply($orderTransaction, $system);

            $stateChanged = $stateChanged || PaymentStateService::stateOf($after) !== $before;
        }

        if ($stateChanged) {
            // The answer shows the transactions as they are now, not as they were loaded.
            $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context) ?? $order;
        }

        return new JsonResponse([
            'transactions' => $this->transactions($order),
            'sync' => self::overall(array_values($outcomes)),
        ]);
    }

    /**
     * One word for several accounts. A failure anywhere means the list may
     * lack payments, so it wins. Otherwise the list is as current as it gets:
     * `throttled` only when no node was asked at all.
     *
     * @param list<string> $outcomes
     */
    private static function overall(array $outcomes): string
    {
        if (in_array(OrderTransactionService::SYNC_FAILED, $outcomes, true)) {
            return OrderTransactionService::SYNC_FAILED;
        }

        if ($outcomes !== [] && !in_array(OrderTransactionService::SYNC_DONE, $outcomes, true)) {
            return OrderTransactionService::SYNC_THROTTLED;
        }

        return OrderTransactionService::SYNC_DONE;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transactions(OrderEntity $order): array
    {
        $transactions = [];

        foreach ($order->getTransactions() ?? [] as $orderTransaction) {
            $entry = [
                'orderTransactionId' => $orderTransaction->getId(),
                'state' => PaymentStateService::stateOf($orderTransaction),
                'stateName' => $orderTransaction->getStateMachineState()?->getTranslation('name'),
            ];

            try {
                $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);
            } catch (InvalidArgumentException) {
                // Named, not skipped: money may have arrived on a record the plugin cannot read,
                // and one such record must not take the other transactions' rows with it.
                $transactions[] = $entry + ['unreadable' => true, 'payments' => []];

                continue;
            }

            if ($intent === null) {
                // Another payment method's transaction, or one of ours that was never quoted.
                continue;
            }

            $transactions[] = $entry + ['unreadable' => false] + ReceivedPaymentsPresenter::present(
                $intent,
                $this->orderTransactionService->findReceivedPayments($intent),
                $this->settlementPolicy
            );
        }

        return $transactions;
    }
}
