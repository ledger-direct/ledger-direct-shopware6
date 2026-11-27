<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Api;

use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Api\ReceivedPaymentsController;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Stellar\HorizonClient;
use Hardcastle\LedgerDirect\Core\Stellar\MemoIdService;
use Hardcastle\LedgerDirect\Core\Stellar\PaymentIntentService as StellarPaymentIntentService;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use Hardcastle\LedgerDirect\Core\Stellar\SyncService as StellarSyncService;
use Hardcastle\LedgerDirect\Core\Testing\FakeConfigProvider;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Hardcastle\LedgerDirect\Tests\Mock\Http\StubHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin route behind "Received payments": every LedgerDirect transaction
 * of an order with the ledger payments on its own account, tag and network.
 *
 * The order transaction service and the core's sync services are the real
 * ones, over the core's in-memory tables, so the chain and the network a row
 * is read from are the ones production reads from. Only Shopware's order
 * repository is a stub.
 */
class ReceivedPaymentsControllerTest extends TestCase
{
    private const ORDER_ID = '0190c1d8a0e24c2b9c1e1a7b1d2e3f50';

    private const ACCOUNT = 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr';

    private const RLUSD = ['currency' => '524C555344000000000000000000000000000000', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'];

    private Context $context;

    private InMemoryXrplTransactionRepository $xrplTable;

    private InMemoryXrplTransactionRepository $xahauTable;

    private InMemoryStellarPaymentRepository $stellarTable;

    private StubHttpClient $httpClient;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->xrplTable = new InMemoryXrplTransactionRepository();
        $this->xahauTable = new InMemoryXrplTransactionRepository();
        $this->stellarTable = new InMemoryStellarPaymentRepository();
        $this->httpClient = new StubHttpClient();
    }

    public function testTheRouteAsksForNoMoreThanReadingTheOrder(): void
    {
        $controller = new \ReflectionClass(ReceivedPaymentsController::class);
        $route = $controller->getMethod('payments')->getAttributes(Route::class)[0]->newInstance();

        $this->assertSame('/api/_action/ledger-direct/order/{orderId}/payments', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertSame(['order:read'], $route->getDefaults()['_acl']);
        $this->assertSame(['api'], $controller->getAttributes(Route::class)[0]->newInstance()->getDefaults()['_routeScope']);
    }

    public function testAnUnknownOrderIs404(): void
    {
        $response = $this->controller(null)->payments(self::ORDER_ID, $this->context);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'order_not_found'], json_decode((string) $response->getContent(), true));
    }

    /**
     * The administration asks for any order it shows; one paid by invoice simply has nothing.
     */
    public function testAnOrderWithoutALedgerDirectTransactionAnswersAnEmptyList(): void
    {
        $this->xrplTable->saveTransactions([$this->payment('HASH-ELSEWHERE', 114729, '40000000')]);

        $byInvoice = $this->order([$this->transaction('tx-invoice', 'open', 'Open', null)]);
        $this->assertSame(['transactions' => []], $this->answer($byInvoice));

        $this->assertSame(['transactions' => []], $this->answer($this->order(null)), 'an order without transactions');
    }

    /**
     * Each attempt has its own tag. A customer who paid the first attempt after the shop
     * cancelled it has paid, and the merchant finds that payment under that attempt, whatever
     * Shopware shows as the order's current transaction.
     */
    public function testEveryLedgerDirectTransactionIsListedWithThePaymentsOnItsOwnTag(): void
    {
        $this->xrplTable->saveTransactions([
            $this->payment('HASH-LATE', 1001, '40000000', ledgerIndex: '500'),
            $this->payment('HASH-TOKEN', 1002, ['currency' => self::RLUSD['currency'], 'value' => '25', 'issuer' => self::RLUSD['issuer']], ledgerIndex: '600'),
            $this->payment('HASH-WRONG', 1002, '25000000', ledgerIndex: '601'),
            $this->payment('HASH-ANOTHER-ORDER', 1003, '1000000', ledgerIndex: '602'),
        ]);

        $answer = $this->answer($this->order([
            $this->transaction('tx-cancelled', 'cancelled', 'Cancelled', $this->xrpQuote(1001)),
            $this->transaction('tx-invoice', 'failed', 'Failed', null),
            $this->transaction('tx-current', 'paid_partially', 'Paid (partially)', $this->rlusdQuote(1002)),
        ]));

        $this->assertSame(['tx-cancelled', 'tx-current'], array_column($answer['transactions'], 'orderTransactionId'));

        [$cancelled, $current] = $answer['transactions'];

        $this->assertSame('cancelled', $cancelled['state']);
        $this->assertSame('Cancelled', $cancelled['stateName']);
        $this->assertFalse($cancelled['unreadable']);
        $this->assertSame(['XRPL', 'testnet', 'XRP', '40', null], [$cancelled['chain'], $cancelled['network'], $cancelled['asset'], $cancelled['amountRequested'], $cancelled['issuer']]);
        $this->assertSame(['HASH-LATE'], array_column($cancelled['payments'], 'hash'));
        $this->assertSame(['value' => '40', 'asset' => 'XRP', 'issuer' => null], $cancelled['payments'][0]['amount']);
        $this->assertTrue($cancelled['payments'][0]['quotedAsset']);
        $this->assertSame('rSender', $cancelled['payments'][0]['sender']);

        $this->assertSame('paid_partially', $current['state']);
        $this->assertSame(['XRPL', 'testnet', 'RLUSD', '40.00', self::RLUSD['issuer']], [$current['chain'], $current['network'], $current['asset'], $current['amountRequested'], $current['issuer']]);
        $this->assertSame(['HASH-WRONG', 'HASH-TOKEN'], array_column($current['payments'], 'hash'), 'newest first');
        $this->assertSame(
            ['HASH-WRONG' => false, 'HASH-TOKEN' => true],
            array_column($current['payments'], 'quotedAsset', 'hash'),
            'XRP on an RLUSD order is listed and marked'
        );
        $this->assertSame(['value' => '25', 'asset' => 'XRP', 'issuer' => null], $current['payments'][0]['amount']);

        $this->assertSame(0, $this->httpClient->ledgerRequests, 'the route reads the table and asks no node');
    }

    /**
     * The same key pair is an account on testnet and mainnet, and the tag counter belongs
     * to the account, so rows of both networks can carry one tag. Test money is not a
     * payment of a mainnet order, and the other way round.
     */
    public function testOnlyPaymentsOnTheIntentsNetworkAreListed(): void
    {
        $this->xrplTable->saveTransactions([
            $this->payment('HASH-TESTNET', 1001, '40000000', network: 'testnet'),
            $this->payment('HASH-MAINNET', 1001, '40000000', network: 'mainnet'),
        ]);

        $onTestnet = $this->answer($this->order([$this->transaction('tx', 'open', 'Open', $this->xrpQuote(1001))]));
        $onMainnet = $this->answer($this->order([$this->transaction('tx', 'open', 'Open', $this->xrpQuote(1001, network: 'mainnet'))]));

        $this->assertSame(['HASH-TESTNET'], array_column($onTestnet['transactions'][0]['payments'], 'hash'));
        $this->assertSame('https://testnet.xrpl.org/transactions/HASH-TESTNET', $onTestnet['transactions'][0]['payments'][0]['explorerUrl']);
        $this->assertSame(['HASH-MAINNET'], array_column($onMainnet['transactions'][0]['payments'], 'hash'));
        $this->assertSame('https://livenet.xrpl.org/transactions/HASH-MAINNET', $onMainnet['transactions'][0]['payments'][0]['explorerUrl']);
    }

    /**
     * An r-address is valid on the XRP Ledger and on Xahau. Each chain has a table of its
     * own, and a transaction is read from its intent's chain.
     */
    public function testAChainsPaymentsComeFromItsOwnTable(): void
    {
        $this->xrplTable->saveTransactions([$this->payment('HASH-XRPL', 1001, '40000000')]);
        $this->xahauTable->saveTransactions([$this->payment('HASH-XAHAU', 1001, '250000000')]);
        $this->stellarTable->savePayments([
            new StellarPayment('testnet', '4294967300097', 'hash-stellar', 1, 'payment', 'GSENDER', 'GMERCHANT', '1001', 'XLM', null, '400.0000000', 1795000000, []),
            new StellarPayment('mainnet', '4294967300098', 'hash-stellar-mainnet', 1, 'payment', 'GSENDER', 'GMERCHANT', '1001', 'XLM', null, '400.0000000', 1795000000, []),
        ]);

        $answer = $this->answer($this->order([
            $this->transaction('tx-xrpl', 'cancelled', 'Cancelled', $this->xrpQuote(1001)),
            $this->transaction('tx-xahau', 'cancelled', 'Cancelled', PaymentIntent::quote(
                type: 'xahau-xah-payment', chain: 'XAHAU', network: 'testnet', baseAsset: 'XAH', quoteCurrency: 'EUR',
                pairing: 'XAH/EUR', exchangeRate: 0.4, amountRequested: 250.0,
                destinationAccount: self::ACCOUNT, destinationTag: 1001,
            )),
            $this->transaction('tx-stellar', 'open', 'Open', PaymentIntent::quote(
                type: 'stellar-xlm-payment', chain: 'STELLAR', network: 'testnet', baseAsset: 'XLM', quoteCurrency: 'EUR',
                pairing: 'XLM/EUR', exchangeRate: 0.25, amountRequested: 400.0,
                destinationAccount: 'GMERCHANT', destinationTag: 1001,
            )),
        ]));

        [$xrpl, $xahau, $stellar] = $answer['transactions'];

        $this->assertSame(['HASH-XRPL'], array_column($xrpl['payments'], 'hash'));
        $this->assertSame(['HASH-XAHAU'], array_column($xahau['payments'], 'hash'));
        $this->assertSame(['value' => '250', 'asset' => 'XAH', 'issuer' => null], $xahau['payments'][0]['amount']);
        $this->assertSame('https://explorer.xahau-test.net/tx/HASH-XAHAU', $xahau['payments'][0]['explorerUrl']);
        $this->assertSame(['hash-stellar:1'], array_column($stellar['payments'], 'id'), 'Stellar: its own table, its own network');
        $this->assertSame(['value' => '400', 'asset' => 'XLM', 'issuer' => null], $stellar['payments'][0]['amount']);
    }

    /**
     * A record the plugin cannot read says so on its own entry and leaves the other
     * transactions of the order their rows.
     */
    public function testAnUnreadableRecordIsNamedAndDoesNotFailTheList(): void
    {
        $this->xrplTable->saveTransactions([$this->payment('HASH-GOOD', 1002, '40000000')]);

        $broken = $this->transaction('tx-broken', 'cancelled', 'Cancelled', null);
        $broken->setCustomFields([OrderTransactionService::CUSTOM_FIELDS_KEY => ['schema_version' => 99]]);

        $answer = $this->answer($this->order([$broken, $this->transaction('tx-good', 'open', 'Open', $this->xrpQuote(1002))]));

        $this->assertSame(
            ['orderTransactionId' => 'tx-broken', 'state' => 'cancelled', 'stateName' => 'Cancelled', 'unreadable' => true, 'payments' => []],
            $answer['transactions'][0]
        );
        $this->assertFalse($answer['transactions'][1]['unreadable']);
        $this->assertSame(['HASH-GOOD'], array_column($answer['transactions'][1]['payments'], 'hash'));
    }

    /**
     * The same for a record with every field there and one of the wrong type: the core
     * throws a TypeError for it, and that must not take the list down either.
     */
    public function testARecordWithAFieldOfTheWrongTypeIsNamedAndDoesNotFailTheList(): void
    {
        $this->xrplTable->saveTransactions([$this->payment('HASH-GOOD', 1002, '40000000')]);

        $broken = $this->transaction('tx-broken', 'cancelled', 'Cancelled', null);
        $broken->setCustomFields([OrderTransactionService::CUSTOM_FIELDS_KEY => ['destination_tag' => null] + $this->xrpQuote(1001)->toArray()]);

        $answer = $this->answer($this->order([$broken, $this->transaction('tx-good', 'open', 'Open', $this->xrpQuote(1002))]));

        $this->assertSame(
            ['orderTransactionId' => 'tx-broken', 'state' => 'cancelled', 'stateName' => 'Cancelled', 'unreadable' => true, 'payments' => []],
            $answer['transactions'][0]
        );
        $this->assertSame(['HASH-GOOD'], array_column($answer['transactions'][1]['payments'], 'hash'));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(OrderEntity $order): array
    {
        $response = $this->controller($order)->payments(self::ORDER_ID, $this->context);
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function controller(?OrderEntity $order): ReceivedPaymentsController
    {
        $orders = $this->createStub(EntityRepository::class);
        $orders->method('search')->willReturn(new EntitySearchResult(
            'order',
            $order === null ? 0 : 1,
            new OrderCollection($order === null ? [] : [$order]),
            null,
            new Criteria(),
            $this->context
        ));

        $httpFactory = new HttpFactory();
        $logger = new NullLogger();
        $prices = new PriceService($this->httpClient, $httpFactory, $logger);
        $config = new FakeConfigProvider();
        $cache = new InMemoryCache();

        $settlementPolicy = new SettlementPolicy();
        $service = new OrderTransactionService(
            $orders,
            $this->createStub(EntityRepository::class),
            $this->createStub(EntityRepository::class),
            new PaymentIntentService($prices, new DestinationTagService($this->xrplTable), $config),
            new SyncService(new XrplClient($this->httpClient, $httpFactory, $httpFactory), $this->xrplTable, $logger),
            new StellarPaymentIntentService($prices, new MemoIdService($this->stellarTable), $config),
            new StellarSyncService(new HorizonClient($this->httpClient, $httpFactory), $this->stellarTable, $logger),
            new SyncThrottle($cache, $logger),
            $logger,
            new PaymentIntentService($prices, new DestinationTagService($this->xahauTable), $config, null, 'XAHAU'),
            new SyncService(new XrplClient($this->httpClient, $httpFactory, $httpFactory, 'XAHAU'), $this->xahauTable, $logger, null, 'XAHAU'),
            new SyncThrottle($cache, $logger, chain: 'XAHAU')
        );

        // The list sets no state; a handler that is asked to fails the test.
        $stateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $stateHandler->expects($this->never())->method($this->anything());

        return new ReceivedPaymentsController($service, $settlementPolicy, new PaymentStateService($stateHandler, $service, $settlementPolicy, $logger));
    }

    /**
     * @param list<OrderTransactionEntity>|null $transactions
     */
    private function order(?array $transactions): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setUniqueIdentifier(self::ORDER_ID);

        if ($transactions !== null) {
            $order->setTransactions(new OrderTransactionCollection($transactions));
        }

        return $order;
    }

    /**
     * @param PaymentIntent|null $intent null: a transaction of another payment method, without a record
     */
    private function transaction(string $id, string $state, string $stateName, ?PaymentIntent $intent): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);
        $stateEntity->setTranslated(['name' => $stateName]);

        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setUniqueIdentifier($id);
        $transaction->setStateMachineState($stateEntity);

        if ($intent !== null) {
            $transaction->setCustomFields([OrderTransactionService::CUSTOM_FIELDS_KEY => $intent->toArray()]);
        }

        return $transaction;
    }

    /**
     * @param string|array{currency: string, value: string, issuer: string} $delivered drops, or a token amount
     */
    private function payment(string $hash, int $tag, string|array $delivered, string $network = 'testnet', string $ledgerIndex = '1000'): XrplTransaction
    {
        return new XrplTransaction(
            network: $network, ledgerIndex: $ledgerIndex, hash: $hash, ctid: 'C000000000000000', account: 'rSender',
            destination: self::ACCOUNT, destinationTag: $tag, date: 848499942,
            meta: ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => $delivered], tx: [],
        );
    }

    private function xrpQuote(int $tag, string $network = 'testnet'): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: $network, baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 40.0,
            destinationAccount: self::ACCOUNT, destinationTag: $tag,
        );
    }

    private function rlusdQuote(int $tag): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'RLUSD', quoteCurrency: 'USD',
            pairing: 'RLUSD/USD', exchangeRate: 1.0,
            amountRequested: ['currency' => self::RLUSD['currency'], 'value' => '40.00', 'issuer' => self::RLUSD['issuer']],
            destinationAccount: self::ACCOUNT, destinationTag: $tag,
        );
    }
}
