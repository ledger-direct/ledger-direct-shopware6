<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * The Shopware side of a LedgerDirect payment: loading order entities and
 * persisting the payment record on the order transaction.
 *
 * Everything about *what* is owed — exchange rate, requested amount,
 * destination tag, matching an on-ledger payment — comes from
 * hardcastle/ledger-direct-core and is not recomputed here.
 */
class OrderTransactionService
{
    /**
     * Storage key inside the order transaction's customFields. Not part of
     * the cross-plugin contract (the PaymentIntent it holds is), so it stays
     * as it was.
     */
    public const CUSTOM_FIELDS_KEY = 'ledger_direct';

    private const BASE_ASSET_BY_PAYMENT_METHOD = [
        PaymentMethodInstaller::XRP_PAYMENT_ID => 'XRP',
        PaymentMethodInstaller::RLUSD_PAYMENT_ID => 'RLUSD',
        PaymentMethodInstaller::USDC_PAYMENT_ID => 'USDC',
    ];

    private EntityRepository $orderRepository;

    private EntityRepository $orderTransactionRepository;

    private EntityRepository $currencyRepository;

    private PaymentIntentService $paymentIntentService;

    private SyncService $syncService;

    private SyncThrottle $syncThrottle;

    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        EntityRepository $currencyRepository,
        PaymentIntentService $paymentIntentService,
        SyncService $syncService,
        SyncThrottle $syncThrottle,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->currencyRepository = $currencyRepository;
        $this->paymentIntentService = $paymentIntentService;
        $this->syncService = $syncService;
        $this->syncThrottle = $syncThrottle;
        $this->logger = $logger;
    }

    /**
     * Retrieves an OrderTransaction (incl. its order and payment method) by ID.
     *
     * Needed since Shopware 6.7: the AbstractPaymentHandler receives only the
     * orderTransactionId via PaymentTransactionStruct, not the loaded entities.
     */
    public function getOrderTransactionById(string $orderTransactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('paymentMethod');
        // PaymentStateService decides on the loaded state; see there.
        $criteria->addAssociation('stateMachineState');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();

        return $orderTransaction instanceof OrderTransactionEntity ? $orderTransaction : null;
    }

    /**
     * Retrieves an order with its associated transactions and currency information.
     */
    public function getOrderWithTransactions(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.stateMachineState');
        // prepareOrderTransactionForXrpl() quotes by payment method.
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));

        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    /**
     * Quotes the order in the asset its payment method stands for and stores
     * the resulting PaymentIntent on the order transaction.
     *
     * An intent already on the transaction is handed to the core so a repeated
     * attempt keeps its destination account and tag — the customer may already
     * be looking at those on the payment page, or have a payment in flight.
     *
     * @throws Exception
     */
    public function prepareOrderTransactionForXrpl(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context
    ): void {
        $paymentMethodId = $orderTransaction->getPaymentMethod()?->getId();
        $baseAsset = self::BASE_ASSET_BY_PAYMENT_METHOD[$paymentMethodId] ?? null;

        if ($baseAsset === null) {
            throw new Exception('Unsupported payment method: ' . (string) $paymentMethodId);
        }

        $intent = $this->paymentIntentService->quoteForOrder(
            $order->getAmountTotal(),
            $this->getQuoteCurrency($order, $context),
            $baseAsset,
            $this->readReusablePaymentIntent($orderTransaction)
        );

        $this->persistPaymentIntent($orderTransaction, $intent, $context);
    }

    /**
     * Syncs the merchant's incoming XRPL transactions and, when they pay
     * this order, records the settlement on the intent.
     *
     * What pays is the core's decision: every payment in the quoted asset on
     * the tag counts and they add up, so a top-up of a shortfall settles; a
     * payment in another asset is the fulfillment only while nothing in the
     * right one has arrived, so the page can say "wrong token". The intent
     * records the hash and ctid of the newest contributing transaction.
     *
     * @param bool $throttled skip the node request when this receiving
     *     account was synced within SyncThrottle's interval and match
     *     against what is stored locally — the payment page and the status
     *     endpoint pass true, the scheduled task never does: it is the
     *     safety net on its own schedule
     *
     * @return PaymentIntent|null the fulfilled intent, or null while nothing
     *     payable has arrived: no transaction on the tag yet, only ones that
     *     delivered nothing measurable (an EscrowCreate lands in the same
     *     table), or only ones in the other asset class — the core logs and
     *     skips those.
     * @throws Exception
     */
    public function syncOrderTransactionWithXrpl(
        OrderTransactionEntity $orderTransaction,
        Context $context,
        bool $throttled = false
    ): ?PaymentIntent {
        $intent = $this->readPaymentIntent($orderTransaction);

        if ($intent === null) {
            return null;
        }

        $this->syncLedger($intent->destinationAccount, $intent->network, $throttled);

        return $this->matchOrderTransaction($orderTransaction, $context);
    }

    /**
     * The "match" half of syncOrderTransactionWithXrpl(): what the local
     * transaction table holds for this order's tag, and whether it pays.
     * The scheduled task syncs each receiving account once and then
     * matches every open order against the table — one node request per
     * account, not one per order.
     *
     * @return PaymentIntent|null as syncOrderTransactionWithXrpl()
     */
    public function matchOrderTransaction(OrderTransactionEntity $orderTransaction, Context $context): ?PaymentIntent
    {
        $intent = $this->readPaymentIntent($orderTransaction);

        if ($intent === null) {
            return null;
        }

        $fulfilledIntent = $this->syncService->findFulfillmentFor($intent)?->applyTo($intent);

        if ($fulfilledIntent === null) {
            return null;
        }

        $this->persistPaymentIntent($orderTransaction, $fulfilledIntent, $context);

        return $fulfilledIntent;
    }

    /**
     * Pulls the account's transactions from the ledger into the local table.
     *
     * Throttled per receiving account and network, not per order: the sync
     * fetches the whole account in one go and matching afterwards is local,
     * so ten waiting customers inside one window cost one node request. The
     * mark is set before the request, not after a successful one — a node
     * that is down must not be hit harder than one that answers.
     *
     * A failed sync is logged, not thrown: the checkout must not go down
     * with the node, and matching still runs against what is stored.
     */
    public function syncLedger(string $destinationAccount, string $network, bool $throttled): void
    {
        if ($throttled) {
            if (!$this->syncThrottle->shouldSync($network, $destinationAccount)) {
                return;
            }

            $this->syncThrottle->markSynced($network, $destinationAccount);
        }

        try {
            $this->syncService->syncTransactions($destinationAccount, $network);

            // One line per node request, at debug level: it is what shows whether the
            // throttle holds — two status calls inside the window must leave one line.
            $this->logger->debug('LedgerDirect: ledger synced', [
                'destination_account' => $destinationAccount,
                'network' => $network,
                'throttled' => $throttled,
            ]);
        } catch (Exception $exception) {
            $this->logger->error('LedgerDirect: ledger sync failed, matching against stored transactions', [
                'destination_account' => $destinationAccount,
                'network' => $network,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Every LedgerDirect order transaction that still waits for money on
     * the ledger — what the scheduled task works through. Oldest first, so
     * a backlog is settled in the order it was placed.
     */
    public function findOpenLedgerDirectTransactions(Context $context, int $limit = 500): OrderTransactionCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('paymentMethodId', array_keys(self::BASE_ASSET_BY_PAYMENT_METHOD)));
        $criteria->addFilter(new EqualsAnyFilter('stateMachineState.technicalName', PaymentStateService::OPEN_STATES));
        $criteria->addAssociation('stateMachineState');
        $criteria->addSorting(new FieldSorting('createdAt'));
        $criteria->setLimit($limit);

        /** @var OrderTransactionCollection $transactions */
        $transactions = $this->orderTransactionRepository->search($criteria, $context)->getEntities();

        return $transactions;
    }

    /**
     * The payment record stored on an order transaction, or null when the
     * order was never prepared for XRPL.
     *
     * Throws on a record that is not a readable schema v1 PaymentIntent —
     * see the retrofit notes: the plugin was never released, so there is no
     * legacy format to keep reading, and quietly ignoring an unreadable
     * record would hide a real problem behind an "unpaid" order.
     */
    public function readPaymentIntent(OrderTransactionEntity $orderTransaction): ?PaymentIntent
    {
        $paymentIntentData = ($orderTransaction->getCustomFields() ?? [])[self::CUSTOM_FIELDS_KEY] ?? null;

        return is_array($paymentIntentData) ? PaymentIntent::fromArray($paymentIntentData) : null;
    }

    /**
     * Like readPaymentIntent(), but for the quoting path, where an
     * unreadable record simply means "quote from scratch" — the customer is
     * about to be given a fresh destination tag and amount anyway.
     */
    private function readReusablePaymentIntent(OrderTransactionEntity $orderTransaction): ?PaymentIntent
    {
        try {
            return $this->readPaymentIntent($orderTransaction);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Writes the intent to the order transaction, replacing any previous one
     * wholesale so no field of an older record can survive underneath.
     */
    private function persistPaymentIntent(
        OrderTransactionEntity $orderTransaction,
        PaymentIntent $intent,
        Context $context
    ): void {
        $customFields = $orderTransaction->getCustomFields() ?? [];
        $customFields[self::CUSTOM_FIELDS_KEY] = $intent->toArray();

        $orderTransaction->setCustomFields($customFields);

        $this->orderTransactionRepository->upsert([
            [
                'id' => $orderTransaction->getId(),
                'customFields' => $customFields,
            ],
        ], $context);
    }

    /**
     * @throws Exception
     */
    private function getQuoteCurrency(OrderEntity $order, Context $context): string
    {
        $currency = $this->currencyRepository
            ->search(new Criteria([$order->getCurrencyId()]), $context)
            ->getEntities()
            ->first();

        if (!$currency instanceof CurrencyEntity) {
            throw new Exception('Currency not found for order ' . $order->getId());
        }

        return $currency->getIsoCode();
    }
}
