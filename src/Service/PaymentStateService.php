<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;

/**
 * What a payment found on the ledger means for the order transaction's
 * state — and the one place that decision is made.
 *
 * Until now the state changed only in the payment handler's finalize(),
 * which Shopware calls when the customer comes back over the returnUrl.
 * A customer who paid and closed the tab never came back, and neither did
 * one whose payment token (30 minutes) had expired by the time they paid:
 * the money was on the ledger and the order stayed open. And a partial
 * payment reached the merchant never — the page kept the customer, so
 * finalize() never ran.
 *
 * Now the status endpoint, the payment page and the scheduled task apply
 * the state right after a sync with a hit, and finalize() has become a
 * formality that tolerates having been beaten to it.
 *
 * Every change is idempotent: the target state is compared with the
 * current one first, because Shopware's state machine has no paid → paid
 * and no paid_partially → paid_partially transition, and either throws.
 */
final class PaymentStateService
{
    /**
     * States in which the transaction still waits for money on the ledger.
     * `reminded` is on the list on purpose: a merchant who reminded the
     * customer is still waiting, and the state machine allows paid and
     * paid_partially from there. paid, cancelled, failed and refunded are
     * closed — whatever closed them, the payment page is over.
     */
    public const OPEN_STATES = [
        OrderTransactionStates::STATE_OPEN,
        OrderTransactionStates::STATE_IN_PROGRESS,
        OrderTransactionStates::STATE_UNCONFIRMED,
        OrderTransactionStates::STATE_PARTIALLY_PAID,
        OrderTransactionStates::STATE_REMINDED,
    ];

    /**
     * The states a customer is in while "away at the payment provider".
     * Only from these does a return without a payment reopen the
     * transaction; open → open is not a transition, and a transaction the
     * merchant cancelled must not be reopened by a late click.
     */
    private const AWAY_STATES = [
        OrderTransactionStates::STATE_UNCONFIRMED,
        OrderTransactionStates::STATE_IN_PROGRESS,
    ];

    public function __construct(
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly OrderTransactionService $orderTransactionService,
        private readonly SettlementPolicy $settlementPolicy,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isOpen(OrderTransactionEntity $orderTransaction): bool
    {
        return in_array(self::stateOf($orderTransaction), self::OPEN_STATES, true);
    }

    /**
     * The transaction's state as loaded — the entity has to carry its
     * stateMachineState association, which OrderTransactionService's
     * loaders make sure of.
     */
    public static function stateOf(OrderTransactionEntity $orderTransaction): ?string
    {
        return $orderTransaction->getStateMachineState()?->getTechnicalName();
    }

    /**
     * Syncs the ledger for this transaction's receiving account and, when
     * something pays the order, moves the transaction to the matching
     * state. The one step the status endpoint, the payment page and the
     * scheduled task share.
     *
     * @return OrderTransactionEntity the transaction as it is afterwards —
     *     reloaded when its state changed, so the caller decides on the
     *     state that actually stuck, not on the object from before the sync
     */
    public function syncAndApply(OrderTransactionEntity $orderTransaction, Context $context, bool $throttled): OrderTransactionEntity
    {
        if (!$this->isOpen($orderTransaction)) {
            return $orderTransaction;
        }

        return $this->applyAndReload(
            $orderTransaction,
            $this->orderTransactionService->syncOrderTransactionWithXrpl($orderTransaction, $context, $throttled),
            $context
        );
    }

    /**
     * The same, against the local transaction table only — for the
     * scheduled task, which has synced the receiving account once for all
     * open orders beforehand.
     */
    public function matchAndApply(OrderTransactionEntity $orderTransaction, Context $context): OrderTransactionEntity
    {
        if (!$this->isOpen($orderTransaction)) {
            return $orderTransaction;
        }

        return $this->applyAndReload(
            $orderTransaction,
            $this->orderTransactionService->matchOrderTransaction($orderTransaction, $context),
            $context
        );
    }

    private function applyAndReload(OrderTransactionEntity $orderTransaction, ?PaymentIntent $fulfilledIntent, Context $context): OrderTransactionEntity
    {
        if ($fulfilledIntent === null || $this->applyState($orderTransaction, $fulfilledIntent, $context) === null) {
            return $orderTransaction;
        }

        return $this->orderTransactionService->getOrderTransactionById($orderTransaction->getId(), $context)
            ?? $orderTransaction;
    }

    /**
     * Moves the transaction to the state the intent calls for:
     *
     *   settled                     → paid
     *   a hit that does not settle  → paid_partially — also for a payment
     *                                 in the wrong asset: nothing credited,
     *                                 but money is there, and Shopware has
     *                                 no closer state
     *   nothing arrived             → unchanged
     *
     * Only ever from an open state: a transaction the merchant closed by
     * hand keeps its state. Idempotent, see the class comment.
     *
     * @return string|null the state set, or null when nothing changed
     */
    public function applyState(OrderTransactionEntity $orderTransaction, PaymentIntent $intent, Context $context): ?string
    {
        if ($intent->hash === null) {
            return null;
        }

        $current = self::stateOf($orderTransaction);

        if ($current === null) {
            $this->logger->warning('LedgerDirect: order transaction loaded without its state, leaving it as it is', [
                'order_transaction_id' => $orderTransaction->getId(),
            ]);

            return null;
        }

        if (!in_array($current, self::OPEN_STATES, true)) {
            return null;
        }

        $target = $this->settlementPolicy->isSettled($intent)
            ? OrderTransactionStates::STATE_PAID
            : OrderTransactionStates::STATE_PARTIALLY_PAID;

        if ($current === $target) {
            return null;
        }

        if ($target === OrderTransactionStates::STATE_PAID) {
            $this->stateHandler->paid($orderTransaction->getId(), $context);
        } else {
            $this->stateHandler->paidPartially($orderTransaction->getId(), $context);
        }

        $this->logger->info('LedgerDirect: order transaction state applied from the ledger', [
            'order_transaction_id' => $orderTransaction->getId(),
            'from' => $current,
            'to' => $target,
            'hash' => $intent->hash,
            'requested' => $intent->amountRequested,
            'paid' => $intent->amountPaid,
            'wrong_asset' => $this->settlementPolicy->isWrongAsset($intent),
        ]);

        return $target;
    }

    /**
     * For finalize(): the customer came back over the returnUrl and nothing
     * has arrived. Reopens the transaction only from the states Shopware
     * parks it in while the customer is away.
     */
    public function reopenIfAway(OrderTransactionEntity $orderTransaction, Context $context): void
    {
        if (in_array(self::stateOf($orderTransaction), self::AWAY_STATES, true)) {
            $this->stateHandler->reopen($orderTransaction->getId(), $context);
        }
    }
}
