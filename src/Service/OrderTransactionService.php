<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\StablecoinProvider;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class OrderTransactionService
{
    public const METADATA_VERSION = 1.0;

    public const DEFAULT_EXPIRY = 60 * 15; // 15 minutes

    private ConfigurationService $configurationService;

    private EntityRepository $orderRepository;


    private EntityRepository $orderTransactionRepository;

    private XrplTxService $xrplSyncService;


    private EntityRepository $currencyRepository;

    private CryptoPriceProviderInterface $xrpPriceProvider;

    private CryptoPriceProviderInterface $rlusdPriceProvider;

    private CryptoPriceProviderInterface $usdcPriceProvider;

    private StablecoinProvider $stablecoinProvider;

    public function __construct(
        ConfigurationService $configurationService,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        XrplTxService    $xrplSyncService,
        EntityRepository $currencyRepository,
        CryptoPriceProviderInterface $xrpPriceProvider,
        CryptoPriceProviderInterface $rlusdPriceProvider,
        CryptoPriceProviderInterface $usdcPriceProvider,
        StablecoinProvider $stablecoinProvider
    )
    {
        $this->configurationService = $configurationService;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->xrplSyncService = $xrplSyncService;
        $this->currencyRepository = $currencyRepository;
        $this->xrpPriceProvider = $xrpPriceProvider;
        $this->rlusdPriceProvider = $rlusdPriceProvider;
        $this->usdcPriceProvider = $usdcPriceProvider;
        $this->stablecoinProvider = $stablecoinProvider;
    }

    /**
     * Retrieves an order with its associated transactions and currency information
     *
     * @param string $orderId The ID of the order to retrieve
     * @param Context $context The Shopware context
     * @return Entity|null The order entity with transactions
     */
    public function getOrderWithTransactions(string $orderId, Context $context): ?Entity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('transactions');
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));

        return $this->orderRepository->search(
            $criteria,
            $context
        )->first();
    }

    /**
     * Get Crypto price for Order
     *
     * @param OrderEntity $order
     * @param Context $context
     * @param string $cryptoCode
     * @param string|null $network
     * @return array
     * @throws Exception
     */
    public function getCryptoPriceForOrder(
        OrderEntity $order,
        Context $context,
        string $cryptoCode,
        ?string $network = null
    ): array
    {
        $currency = $this->currencyRepository->search(new Criteria([$order->getCurrencyId()]), $context)->first();
        $currencyAmountTotal = $order->getAmountTotal();

        if ($cryptoCode === 'XRP'){
            $exchangeRate = $this->xrpPriceProvider->getCurrentExchangeRate($currency->getIsoCode());
            $amountRequested = $currencyAmountTotal / $exchangeRate;
        } elseif ($cryptoCode === 'RLUSD') {
            $exchangeRate = $this->rlusdPriceProvider->getCurrentExchangeRate($currency->getIsoCode());
            $amountRequested = $this->stablecoinProvider->getRLUSDAmount(
                $network,
                (string) round($currencyAmountTotal / $exchangeRate, 2)
            );
        } elseif ($cryptoCode === 'USDC') {
            $exchangeRate = $this->usdcPriceProvider->getCurrentExchangeRate($currency->getIsoCode());
            $amountRequested = $this->stablecoinProvider->getUSDCAmount(
                $network,
                (string) round($currencyAmountTotal / $exchangeRate, 2)
            );
        } else {
            throw new Exception('Unsupported crypto code: ' . $cryptoCode);
        }

        return [
            'pairing' => XrpPriceProvider::CRYPTO_CODE . '/' . $currency->getIsoCode(),
            'exchange_rate' => $exchangeRate,
            'amount_requested' => $amountRequested
        ];
    }

    /**
     * Prepares OrderTransaction for XRPL payment
     *
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @throws Exception
     */
    public function prepareOrderTransactionForXrpl(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context
    ): void
    {
        $paymentMethod = $orderTransaction->getPaymentMethod();

        $network = $this->configurationService->getNetwork(); // use NetworkId
        $destination = $this->configurationService->getDestinationAccount();
        $destinationTag = $this->xrplSyncService->generateDestinationTag();

        $transactionCustomFields = [
            'ledger_direct' => [
                'version' => self::METADATA_VERSION,
                'chain' => 'XRPL',
                'network' => $network,
                'destination_account' => $destination,
                'destination_tag' => $destinationTag
            ]
        ];

        $this->addCustomFieldsToTransaction($orderTransaction, $transactionCustomFields, $context);

        match ($paymentMethod->getId()) {
            PaymentMethodInstaller::XRP_PAYMENT_ID => $this->prepareXrpPayment($order, $orderTransaction, $context, $network),
            PaymentMethodInstaller::RLUSD_PAYMENT_ID => $this->prepareRlusdPayment($order, $orderTransaction, $context, $network),
            PaymentMethodInstaller::USDC_PAYMENT_ID => $this->prepareUsdcPayment($order, $orderTransaction, $context, $network),
            default => throw new Exception('Unsupported payment method: ' . $paymentMethod->getId())

        };
    }

    /**
     * Prepare XRP payment for OrderTransaction
     *
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @param string $network
     * @throws Exception
     */
    private function prepareXrpPayment(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context,
        string $network = 'testnet'
    ): void
    {

        $transactionCustomFields = [
            'ledger_direct' => $this->getCryptoPriceForOrder($order, $context, 'XRP', $network)
        ];
        $transactionCustomFields['ledger_direct']['network'] = $network;
        $transactionCustomFields['ledger_direct']['type'] = 'xrp-payment';

        $this->addCustomFieldsToTransaction($orderTransaction, $transactionCustomFields, $context);
    }

    /**
     * Prepare RLUSD payment for OrderTransaction
     *
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @param string $network
     * @throws Exception
     */
    private function prepareRlusdPayment(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context,
        string $network = 'Testnet'
    ): void
    {
        //if (!$this->configurationService->isRlusdEnabled()) {
        //    throw new Exception('RLUSD payments are not enabled in the configuration.');
        //}

        $rlusdPriceCustomFields = [
            'ledger_direct' => $this->getCryptoPriceForOrder($order, $context, 'RLUSD', $network)
        ];
        $rlusdPriceCustomFields['ledger_direct']['network'] = $network;
        $rlusdPriceCustomFields['ledger_direct']['type'] = 'rlusd-payment';

        $this->addCustomFieldsToTransaction($orderTransaction, $rlusdPriceCustomFields, $context);
    }

    /**
     * Prepare USDC payment for OrderTransaction
     *
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @param string $network
     * @throws Exception
     */
    private function prepareUsdcPayment(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context,
        string $network = 'Testnet'
    ): void
    {
        $usdcPriceCustomFields = [
            'ledger_direct' => $this->getCryptoPriceForOrder($order, $context, 'USDC', $network)
        ];
        $usdcPriceCustomFields['ledger_direct']['network'] = $network;
        $usdcPriceCustomFields['ledger_direct']['type'] = 'usdc-payment';

        $this->addCustomFieldsToTransaction($orderTransaction, $usdcPriceCustomFields, $context);
    }

    /**
     * Sync Shopware OrderTransaction with XRPL transaction data
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @return array|null
     * @throws Exception
     */
    public function syncOrderTransactionWithXrpl(OrderTransactionEntity $orderTransaction, Context $context): ?array
    {
        $customFields = $orderTransaction->getCustomFields();
        if (isset($customFields['ledger_direct']['destination_account']) && isset($customFields['ledger_direct']['destination_tag'])) {

            // TODO: Exception when orderTransaction.customFields are different form xrpl_tx

            $this->xrplSyncService->syncTransactions($customFields['ledger_direct']['destination_account']);

            $tx = $this->xrplSyncService->findTransaction(
                $customFields['ledger_direct']['destination_account'],
                (int)$customFields['ledger_direct']['destination_tag']
            );

            if ($tx) {
                $txMeta = json_decode($tx['meta'], true);

                if (is_array($txMeta['delivered_amount'])) {
                    $amount = $txMeta['delivered_amount'];
                } else {
                    $amount = $this->dropsToXrp($txMeta['delivered_amount']);
                }

                $this->addCustomFieldsToTransaction($orderTransaction, [
                    'ledger_direct' => [
                        'hash' => $tx['hash'],
                        'ctid' => $tx['ctid'],
                        'delivered_amount' => $amount
                    ]
                ], $context);

                return $tx;
            }
        }

        return null;
    }

    /**
     * Adds or updates custom fields in the OrderTransaction and persists the changes.
     *
     * @param OrderTransactionEntity $orderTransaction The order transaction entity to update
     * @param array $customFields The custom fields to add or update
     * @param Context $context The Shopware context
     */
    private function addCustomFieldsToTransaction(OrderTransactionEntity $orderTransaction, array $customFields, Context $context): void
    {
        $existingCustomFields = $orderTransaction->getCustomFields() ?? [];
        $mergedCustomFields = array_replace_recursive($existingCustomFields, $customFields);

        $orderTransaction->setCustomFields($mergedCustomFields);

        $this->orderTransactionRepository->upsert([
            [
                'id' => $orderTransaction->getId(),
                'customFields' => $mergedCustomFields,
            ],
        ], $context);
    }

    /**
     * Convert drops (string or int) to XRP decimal string.
     *
     * @param string|int $drops
     * @return string
     */
    private function dropsToXrp(string|int $drops): string
    {
        // Ensure string for bcmath-like formatting without extension
        $dropsStr = (string)$drops;
        // Left pad with zeros to at least 7 digits to safely insert decimal point for 6 fractional places
        $dropsStr = ltrim($dropsStr, '+');
        $isNegative = str_starts_with($dropsStr, '-');
        if ($isNegative) {
            $dropsStr = substr($dropsStr, 1);
        }
        $dropsStr = ltrim($dropsStr, '0');
        if ($dropsStr === '') {
            return '0';
        }
        if (strlen($dropsStr) <= 6) {
            $xrp = '0.' . str_pad($dropsStr, 6, '0', STR_PAD_LEFT);
        } else {
            $intPart = substr($dropsStr, 0, -6);
            $fracPart = substr($dropsStr, -6);
            // Trim trailing zeros in fractional part
            $fracPart = rtrim($fracPart, '0');
            $xrp = $fracPart === '' ? $intPart : $intPart . '.' . $fracPart;
        }
        return $isNegative ? '-' . $xrp : $xrp;
    }
}