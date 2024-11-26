<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Exception;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\XRPL_PHP\Models\Common\Amount;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use function Hardcastle\XRPL_PHP\Sugar\dropsToXrp;

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
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity|null
     */
    public function getOrderWithTransactions(string $orderId, Context $context): ?OrderEntity
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

    // prepareXrplOrderTransaction
    public function prepareOrderTransactionForXrpl(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context
    ): void
    {
        $paymentMethod = $orderTransaction->getPaymentMethod();

        $network = $this->configurationService->isTest() ? 'Testnet' : 'Mainnet'; // TODO: Use NetworkId
        $destination = $this->configurationService->getDestinationAccount();
        $destinationTag = $this->xrplSyncService->generateDestinationTag();

        $xrplCustomFields = [
            'xrpl' => [
                'type' => 'xrp-payment',
                'network' => $network,
                'destination_account' => $destination,
                'destination_tag' => $destinationTag // TODO: Use consistent naming or use separate service
            ]
        ];

        $this->addCustomFieldsToTransaction($orderTransaction, $xrplCustomFields, $context);

        match ($paymentMethod->getId()) {
            PaymentMethodInstaller::XRP_PAYMENT_ID => $this->prepareXrpPayment($order, $orderTransaction, $context),
            PaymentMethodInstaller::TOKEN_PAYMENT_ID => $this->prepareTokenPayment($order, $orderTransaction, $context),
        };

        // Throw exception here
    }

    private function prepareXrpPayment(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context
    ): void
    {

        $xrpPriceCustomFields = [
            'xrpl' => $this->getCurrentXrpPriceForOrder($order, $context)
        ];
        $xrpPriceCustomFields['xrpl']['type'] = 'xrp-payment';

        $this->addCustomFieldsToTransaction($orderTransaction, $xrpPriceCustomFields, $context);
    }

    private function prepareTokenPayment(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context): void
    {
        $issuer = $this->configurationService->getIssuer();
        $tokenName = $order->getCurrency()->getIsoCode();
        $tokenAmountCustomFields = [
            'xrpl' => [
                'type' => 'token',
                'issuer' => $issuer,
                'currency' => $tokenName,
                'value' => $order->getAmountTotal(),
            ]
        ];

        $this->addCustomFieldsToTransaction($orderTransaction, $tokenAmountCustomFields, $context);
    }

    /**
     * Get XRP price for Order
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     * @throws Exception
     */
    public function getCurrentXrpPriceForOrder(OrderEntity $order, Context $context): array
    {
        $currency = $this->currencyRepository->search(new Criteria([$order->getCurrencyId()]), $context)->first();
        $currencyAmountTotal = $order->getAmountTotal();
        $exchangeRate = $this->priceProvider->getCurrentExchangeRate($currency->getIsoCode());

        return [
            'pairing' => XrpPriceProvider::CRYPTO_CODE . '/' . $currency->getIsoCode(),
            'exchange_rate' => $exchangeRate,
            'amount_requested' => $currencyAmountTotal / $exchangeRate
        ];
    }

    public function syncOrderTransactionWithXrpl(OrderTransactionEntity $orderTransaction, Context $context): ?array
    {
        $customFields = $orderTransaction->getCustomFields();
        if (isset($customFields['xrpl']['destination_account']) && isset($customFields['xrpl']['destination_tag'])) {

            // TODO: Exception when orderTransaction.customFields are different form xrpl_tx

            $this->xrplSyncService->syncTransactions($customFields['xrpl']['destination_account']);

            $tx = $this->xrplSyncService->findTransaction(
                $customFields['xrpl']['destination_account'],
                (int)$customFields['xrpl']['destination_tag']
            );

            if ($tx) {
                $txMeta = json_decode($tx['meta'], true);

                if (is_array($txMeta['delivered_amount'])) {
                    $amount = $txMeta['delivered_amount']['value'];
                } else {
                    $amount = dropsToXrp($txMeta['delivered_amount']);
                }

                $this->addCustomFieldsToTransaction($orderTransaction, [
                    'xrpl' => [
                        'hash' => $tx['hash'],
                        'ctid' => $tx['ctid'], // TODO: Add CTID here
                        'delivered_amount' => $amount
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
        $mergedCustomFields = array_replace_recursive($existingCustomFields, $customFields);

        $orderTransaction->setCustomFields($mergedCustomFields);

        $this->orderTransactionRepository->upsert([
            [
                'id' => $orderTransaction->getId(),
                'customFields' => $mergedCustomFields,
            ],
        ], $context);
    }
}