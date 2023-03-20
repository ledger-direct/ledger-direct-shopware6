<?php declare(strict_types=1);

namespace LedgerDirect\Service;

use Exception;
use LedgerDirect\Provider\XrpPriceProvider;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use LedgerDirect\Provider\CryptoPriceProviderInterface;

class OrderTransactionService
{
    private ConfigurationService $configurationService;

    private EntityRepository $orderRepository;


    private EntityRepository $orderTransactionRepository;

    private XrplTxService $xrplSyncService;


    private EntityRepository $currencyRepository;

    private CryptoPriceProviderInterface $priceProvider;

    public function __construct(
        ConfigurationService $configurationService,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        XrplTxService    $xrplSyncService,
        EntityRepository $currencyRepository,
        CryptoPriceProviderInterface $priceProvider
    )
    {
        $this->configurationService = $configurationService;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->xrplSyncService = $xrplSyncService;
        $this->currencyRepository = $currencyRepository;
        $this->priceProvider = $priceProvider;
    }

    /**
     *
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     * @throws Exception
     */
    public function getCurrentPriceForOrder(OrderEntity $order, Context $context): array
    {
        $currency = $this->currencyRepository->search(new Criteria([$order->getCurrencyId()]), $context)->first();
        $currencyAmountTotal = $order->getAmountTotal();
        $xrpUnitPrice = $this->priceProvider->getCurrentExchangeRate($currency->getIsoCode());

        return [
            'pairing' => XrpPriceProvider::CRYPTO_CODE . '/' . $currency->getIsoCode(),
            'exchange_rate' => $xrpUnitPrice,
            'amount' => $currencyAmountTotal / $xrpUnitPrice
        ];
    }

    public function getOrderWithTransactions(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));

        return $this->orderRepository->search(
            $criteria,
            $context
        )->first();
    }

    public function prepareOrderTransactionForXrpl(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context
    ): void
    {
        $destination = $this->configurationService->getDestinationAccount();
        $destinationTag = $this->xrplSyncService->generateDestinationTag();

        $xrplCustomFields = [
            'type' => 'xrp-payment',
            'destination_account' => $destination,
            'destination_tag' => $destinationTag // TODO: Use consistent naming or use separate service
        ];
        $orderPriceCustomFields = $this->getCurrentPriceForOrder($order, $context);

        $customFields = [
            'xrpl' => array_merge($xrplCustomFields, $orderPriceCustomFields)
        ];

        $this->addCustomFieldsToTransaction($orderTransaction, $customFields, $context);


    }

    public function syncOrderTransactionWithXrpl(OrderTransactionEntity $orderTransaction, Context $context): ?array
    {
        $customFields = $orderTransaction->getCustomFields();
        if (isset($customFields['xrpl']['destination']) && isset($customFields['xrpl']['destination_tag'])) {

            // TODO: Exception when orderTransaction.customFields are different form xrpl_tx

            $this->xrplSyncService->syncTransactions($customFields['xrpl']['destination']);

            $tx = $this->xrplSyncService->findTransaction(
                $customFields['xrpl']['destination'],
                (int)$customFields['xrpl']['destination_tag']
            );

            // TODO: Validate XRP amount

            if ($tx) {
                $this->addCustomFieldsToTransaction($orderTransaction, [
                    'xrpl' => [
                        'hash' => $tx['hash'],
                        'ctid' => $tx['hash']
                    ]
                ], $context);

                return $tx;
            }
        }

        return null;
    }

    private function addCustomFieldsToTransaction(OrderTransactionEntity $orderTransaction, array $customFields, Context $context): void
    {
        $existingCustomFields = $orderTransaction->getCustomFields() ?? [];

        //In case that the cache kicks in update the current struct either to avoid any misbehavior when working with custom fields in later steps.
        $orderTransaction->setCustomFields(array_merge_recursive($existingCustomFields, $customFields));

        $this->orderTransactionRepository->upsert([
            [
                'id' => $orderTransaction->getId(),
                'customFields' => array_merge_recursive($existingCustomFields, $customFields),
            ],
        ], $context);
    }
}