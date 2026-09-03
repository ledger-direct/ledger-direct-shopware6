<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use InvalidArgumentException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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

    public function __construct(
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        EntityRepository $currencyRepository,
        PaymentIntentService $paymentIntentService,
        SyncService $syncService
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->currencyRepository = $currencyRepository;
        $this->paymentIntentService = $paymentIntentService;
        $this->syncService = $syncService;
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
     * Syncs the merchant's incoming XRPL transactions and, when one matches
     * this order's destination tag, records the settlement on the intent.
     *
     * @return PaymentIntent|null the fulfilled intent, or null while the
     *     payment has not arrived (or arrived as something that delivered
     *     nothing measurable, e.g. an EscrowCreate to the same account).
     * @throws Exception
     */
    public function syncOrderTransactionWithXrpl(
        OrderTransactionEntity $orderTransaction,
        Context $context
    ): ?PaymentIntent {
        $intent = $this->readPaymentIntent($orderTransaction);

        if ($intent === null) {
            return null;
        }

        $this->syncService->syncTransactions($intent->destinationAccount, $intent->network);

        $transaction = $this->syncService->findTransaction($intent->destinationAccount, $intent->destinationTag);

        if ($transaction === null) {
            return null;
        }

        $amountPaid = $transaction->getDeliveredAmount();

        if ($amountPaid === null) {
            return null;
        }

        $fulfilledIntent = $intent->withFulfillment($transaction->hash, $amountPaid, $transaction->ctid);

        $this->persistPaymentIntent($orderTransaction, $fulfilledIntent, $context);

        return $fulfilledIntent;
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
