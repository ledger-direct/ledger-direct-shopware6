<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Integration\Service;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Hardcastle\LedgerDirect\Service\XrplClientService;
use Hardcastle\LedgerDirect\Service\XrplTxService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use PHPUnit\Framework\TestCase;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use \Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class OrderTransactionServiceTest extends TestCase
{
    use IntegrationTestBehaviour;

    private OrderTransactionService $orderTransactionService;

    /**
     * @noinspection PhpParamsInspection
     */
    private EntityRepository $currencyRepository;

    /**
     * @noinspection PhpParamsInspection
     */
    public function setUp(): void
    {
        $configurationService = ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration());
        $clientService = new XrplClientService($configurationService);
        $xrplTxService = new XrplTxService(
            $clientService,
            $this->getContainer()->get(Connection::class)
        );
        $this->orderTransactionService = new OrderTransactionService(
            $configurationService,
            $this->getContainer()->get('order.repository'),
            $this->getContainer()->get('order_transaction.repository'),
            $xrplTxService,
            $this->getContainer()->get('currency.repository'),
            new XrpPriceProvider(new Client())
        );

        $this->currencyRepository = $this->getContainer()->get('currency.repository');
    }

    public function testGetCurrentXrpPriceForOrder(): void
    {
        $order = $this->createOrder();

        $xrpPrice = $this->orderTransactionService->getCurrentXrpPriceForOrder($order, Context::createDefaultContext());

        $this->assertIsArray($xrpPrice);
        $this->assertArrayHasKey('pairing', $xrpPrice);
        $this->assertArrayHasKey('exchange_rate', $xrpPrice);
        $this->assertArrayHasKey('amount_requested', $xrpPrice);
    }

    private function createOrder(bool $withTransactions = false): OrderEntity
    {
        $currency = $this->currencyRepository->search(
            new Criteria(),
            Context::createDefaultContext()
        )->first();

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setCurrencyId($currency->getId());
        $order->setAmountTotal(100);

        if ($withTransactions) {
            $order->setTransactions(new OrderTransactionCollection());
        }

        return $order;
    }

    private function getOrderFromRepository(string $orderId): OrderEntity
    {
        $orderRepository = $this->getContainer()->get('order.repository');
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $order = $orderRepository->search($criteria, Context::createDefaultContext())->first();

        return $order;
    }

    public function getName(): string
    {
        return 'test_ledger_direct_order_transaction_service';
    }
}