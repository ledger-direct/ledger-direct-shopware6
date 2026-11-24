<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which of an order's transactions a request to the payment page, its poll
 * or the Store API routes is about.
 *
 * An order has one transaction until the customer changes the payment method
 * after the order (the account's "change payment method", a failed payment's
 * second try). Shopware then cancels the transactions it has and creates a
 * new one (SetPaymentOrderRoute), and that newest one is what its payment
 * processor hands to the handler's pay(). Page, poll, refresh and the Store
 * API routes are about that current transaction, never about the oldest.
 *
 * "Current" is read as Shopware reads it: the newest transaction,
 * `getTransactions()->last()` on the collection sorted by creation
 * (OrderTransactionService::getOrderWithTransactions()). That is what
 * OrderService::isPaymentChangeableByTransactionState() and
 * SetPaymentOrderRoute::tryTransition() take, and after a change it is the
 * transaction PaymentProcessor::getCurrentOrderTransaction() finds, the
 * newest one in the initial state. The primary transaction would say the
 * same, but Shopware reads it only behind the v6.8.0.0 flag, and 6.6 does
 * not have it; it is not asked here.
 *
 * Three things follow from an order having had more than one attempt:
 *
 * - The current transaction may be another payment method's. Then nothing
 *   waits for a ledger payment any more, though an attempt before it was
 *   ours: `quoted` is that attempt, the newest one with a payment record.
 * - A browser tab may still show an attempt that is no longer current. The
 *   page names the transaction it rendered (TRANSACTION_ID_PARAMETER, from
 *   pay() on) on its poll and its forms; a request naming another one than
 *   the current is `superseded` and must be sent on, or the tab would go on
 *   showing the address, tag and amount of a cancelled attempt.
 * - A request that names no transaction (a bookmark, the link in a mail, an
 *   external front end) is about the current one.
 */
final class PaymentAttempt
{
    /** The query parameter that names the order transaction a page was rendered for. */
    public const TRANSACTION_ID_PARAMETER = 'orderTransactionId';

    private function __construct(
        /** The order's current transaction, whatever its payment method and state. */
        public readonly OrderTransactionEntity $current,
        /** The newest transaction with a LedgerDirect payment record: $current, one before it, or null for an order never quoted. */
        public readonly ?OrderTransactionEntity $quoted,
        /** The request names a transaction that is not the current one. */
        public readonly bool $superseded,
    ) {
    }

    /**
     * @param OrderEntity $order with its transactions, oldest first
     *
     * @return self|null null for an order without a transaction
     */
    public static function of(OrderEntity $order, Request $request, OrderTransactionService $orderTransactionService): ?self
    {
        $transactions = $order->getTransactions();
        $current = $transactions?->last();

        if ($transactions === null || !$current instanceof OrderTransactionEntity) {
            return null;
        }

        $quoted = null;

        foreach (array_reverse($transactions->getElements()) as $transaction) {
            if ($orderTransactionService->readPaymentIntent($transaction) !== null) {
                $quoted = $transaction;

                break;
            }
        }

        $named = $request->get(self::TRANSACTION_ID_PARAMETER);

        return new self($current, $quoted, is_string($named) && $named !== '' && $named !== $current->getId());
    }

    /**
     * The current transaction carries a LedgerDirect payment record: there
     * is, or was, something to pay on the ledger for the order as it stands.
     */
    public function currentIsQuoted(): bool
    {
        return $this->quoted !== null && $this->quoted->getId() === $this->current->getId();
    }

    /**
     * The request is about the current transaction and that one is ours: the
     * only case in which the ledger is synced, a state applied or payment
     * instructions handed out.
     */
    public function isCurrent(): bool
    {
        return !$this->superseded && $this->currentIsQuoted();
    }
}
