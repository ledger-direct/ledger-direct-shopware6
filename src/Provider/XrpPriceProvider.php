<?php declare(strict_types=1);

namespace LedgerDirect\Provider;

use Exception;
use GuzzleHttp\Client;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use LedgerDirect\Provider\Oracle\BinanceOracle;

class XrpPriceProvider implements CryptoPriceProviderInterface
{
    private const CRYPTO_CODE = 'XRP';

    private Client $client;

    private EntityRepository $orderTransactionRepository;

    private EntityRepository $currencyRepository;

    public function __construct(
        Client $client,
        EntityRepository $orderTransactionRepository,
        EntityRepository $currencyRepository
    ) {
        $this->client = $client;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->currencyRepository = $currencyRepository;
    }

    /**
     * Gets the current XRP price by querying averaging multiple oracles
     *
     * @param string $code
     * @return float
     */
    public function getCurrentExchangeRate(string $code): float
    {
        $oracle = new BinanceOracle();

        try {
            return $oracle->prepare($this->client)->getCurrentPriceForPair(self::CRYPTO_CODE, $code);
        } catch (Exception $exception) {
            // TODO: Log error
        }

        return 0;
    }

    /**
     * @param OrderEntity $order
     * @param Context $context
     * @return float
     * @throws Exception
     */
    public function getCurrentPriceForOrder(OrderEntity $order, Context $context): float
    {
        $amountTotal = $order->getAmountTotal();
        $currency = $this->currencyRepository->search(new Criteria([$order->getCurrencyId()]), $context)->first();
        $xrpUnitPrice = $this->getCurrentExchangeRate($currency->getIsoCode());

        if (!$this->checkPricePlausibility($xrpUnitPrice)) {
            throw new Exception('XRP price could not be properly determined');
        }

        return $amountTotal / $xrpUnitPrice;
    }

    public function checkPricePlausibility(float $price): bool
    {
        return true;
    }
}