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
use Hardcastle\LedgerDirect\Core\Stellar\SyncService as StellarSyncService;
use Hardcastle\LedgerDirect\Core\Testing\FakeConfigProvider;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryStellarPaymentRepository;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryXrplTransactionRepository;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Hardcastle\LedgerDirect\Tests\Mock\Http\HorizonStub;
use Hardcastle\LedgerDirect\Tests\Mock\Http\LedgerNodeStub;
use Hardcastle\LedgerDirect\Tests\Mock\Http\StubHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The button of the "Received payments" card: fetch what is on the ledger
 * for the order's receiving accounts, then answer the list.
 *
 * The list alone reads what the shop has stored. Once a transaction is no
 * longer open, nothing but the scheduled task syncs its account, so a second
 * payment on a paid order, and a payment on a cancelled attempt, appeared up
 * to five minutes late.
 *
 * As in the list's own tests the order transaction service, the state service
 * and the core's sync are the real ones over the core's in-memory tables. The
 * node is a stub that knows which account it was asked for and can be down;
 * the state handler is a mock, so a state that is set where none should be
 * fails the test.
 */
class ReceivedPaymentsRefreshTest extends TestCase
{
    private const ORDER_ID = '0190c1d8a0e24c2b9c1e1a7b1d2e3f50';

    private const ACCOUNT = 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr';

    /** A receiving account the shop used before the merchant configured ACCOUNT. */
    private const EARLIER_ACCOUNT = 'rJdfC6X2L6tTURK7h214Q3MW3a4RrbzCa8';

    private const STELLAR_ACCOUNT = 'GA5ZSEJYB37JRC5AVCIA5MOP4RHTM335X2KGX3IHOJAPP5RE34K4KZVN';

    private Context $context;

    private InMemoryXrplTransactionRepository $xrplTable;

    private InMemoryXrplTransactionRepository $xahauTable;

    private LedgerNodeStub $xrplNode;

    private LedgerNodeStub $xahauNode;

    private HorizonStub $horizon;

    private OrderTransactionStateHandler&MockObject $stateHandler;

    private OrderEntity $order;

    private ?ReceivedPaymentsController $controller = null;

    protected function setUp(): void
    {
        // A user of the administration, not the system: what the route does in the user's name can be told from what it does not.
        $this->context = new Context(new AdminApiSource('0190c1d8a0e24c2b9c1e1a7b1d2e3f51'));
        $this->xrplTable = new InMemoryXrplTransactionRepository();
        $this->xahauTable = new InMemoryXrplTransactionRepository();
        $this->xrplNode = new LedgerNodeStub();
        $this->xahauNode = new LedgerNodeStub();
        $this->horizon = new HorizonStub();
        $this->stateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->controller = null;
    }

    public function testTheRouteAsksForNoMoreThanReadingTheOrder(): void
    {
        $route = (new \ReflectionClass(ReceivedPaymentsController::class))->getMethod('refresh')->getAttributes(Route::class)[0]->newInstance();

        $this->assertSame('/api/_action/ledger-direct/order/{orderId}/payments/refresh', $route->getPath());
        $this->assertSame(['POST'], $route->getMethods());
        $this->assertSame(['order:read'], $route->getDefaults()['_acl'], 'the button decides nothing; whoever may read the order may ask the ledger for it');
    }

    /**
     * A paid order is not synced by the page or the poll any more. A second
     * payment on its tag is on the ledger and not in the table.
     */
    public function testASecondPaymentOnASettledOrderShowsAfterTheRefresh(): void
    {
        $paid = $this->xrpQuote(1001)->withFulfillment('HASH-FIRST', 40.0, 'C0000064000100001');
        $this->givenOrder($this->transaction('tx-paid', 'paid', $paid));
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-FIRST', 1001, '40000000', 100);
        $this->xrplTable->saveTransactions($this->asStored($this->xrplNode));
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-SECOND', 1001, '40000000', 101);
        $this->stateHandler->expects($this->never())->method($this->anything());

        $before = $this->list();
        $after = $this->refresh();

        $this->assertSame(['HASH-FIRST'], array_column($before['transactions'][0]['payments'], 'hash'));
        $this->assertSame(['HASH-SECOND', 'HASH-FIRST'], array_column($after['transactions'][0]['payments'], 'hash'), 'newest first');
        $this->assertSame('synced', $after['sync']);
        $this->assertSame('paid', $after['transactions'][0]['state']);
    }

    /**
     * A customer can pay an attempt the shop has given up on. The row is
     * what the merchant needs to see; the state stays what it was closed with.
     */
    public function testAPaymentOnACancelledAttemptShowsAfterTheRefresh(): void
    {
        $this->givenOrder($this->transaction('tx-cancelled', 'cancelled', $this->xrpQuote(1001)));
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-LATE', 1001, '40000000', 100);
        $this->stateHandler->expects($this->never())->method($this->anything());

        $before = $this->list();
        $after = $this->refresh();

        $this->assertSame([], $before['transactions'][0]['payments']);
        $this->assertSame(['HASH-LATE'], array_column($after['transactions'][0]['payments'], 'hash'));
        $this->assertSame('cancelled', $after['transactions'][0]['state']);
    }

    /**
     * An open attempt gets its state, as the payment page's poll would give
     * it: the list must not show a payment while the payment card above still
     * says "waiting". The state is the ledger's finding, so it is written as
     * the system and not as the user who pressed the button: that user may
     * only read orders, and did not set the transaction to paid.
     */
    public function testAnOpenAttemptThatTheLedgerSettlesGetsItsStateAndTheAnswerShowsIt(): void
    {
        $open = $this->transaction('tx-open', 'open', $this->xrpQuote(1001));
        $this->givenOrder($open);
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-PAYS', 1001, '40000000', 100);

        $this->stateHandler->expects($this->once())->method('paid')->willReturnCallback(function (string $id, Context $context) use ($open): void {
            $this->assertSame('tx-open', $id);
            $this->assertInstanceOf(SystemSource::class, $context->getSource(), 'not in the name of the user who pressed the button');
            $this->assertSame(Context::SYSTEM_SCOPE, $context->getScope());
            $open->getStateMachineState()?->setTechnicalName('paid');
            $open->getStateMachineState()?->setTranslated(['name' => 'Paid']);
        });

        $this->assertInstanceOf(AdminApiSource::class, $this->context->getSource());
        $after = $this->refresh();

        $this->assertSame('paid', $after['transactions'][0]['state'], 'the answer shows the transaction as it is now');
        $this->assertSame('Paid', $after['transactions'][0]['stateName']);
        $this->assertSame(['HASH-PAYS'], array_column($after['transactions'][0]['payments'], 'hash'));
    }

    /**
     * The sync fetches a whole account. Two attempts of an order on the same
     * account, each with its tag, are one request.
     */
    public function testTwoTransactionsOnOneAccountAreOneRequest(): void
    {
        $this->givenOrder(
            $this->transaction('tx-cancelled', 'cancelled', $this->xrpQuote(1001)),
            $this->transaction('tx-paid', 'paid', $this->xrpQuote(1002)->withFulfillment('HASH-B', 40.0, 'C0000065000100001')),
        );
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-A', 1001, '40000000', 100);
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-B', 1002, '40000000', 101);

        $after = $this->refresh();

        $this->assertSame([self::ACCOUNT], $this->xrplNode->asked);
        $this->assertSame('synced', $after['sync'], 'and its outcome is the one of the request that was made');
        $this->assertSame([['HASH-A'], ['HASH-B']], array_map(static fn (array $t): array => array_column($t['payments'], 'hash'), $after['transactions']));
    }

    /**
     * Asked a second time for the same account, the sync would answer
     * "throttled", and the failure of the one request that was made would be
     * lost: no notice above a list that may lack payments.
     */
    public function testTwoTransactionsOnAnAccountThatDoesNotAnswerAreStillFailed(): void
    {
        $this->givenOrder(
            $this->transaction('tx-cancelled', 'cancelled', $this->xrpQuote(1001)),
            $this->transaction('tx-paid', 'paid', $this->xrpQuote(1002)->withFulfillment('HASH-B', 40.0, 'C0000065000100001')),
        );
        $this->xrplNode->down = true;

        $this->assertSame('failed', $this->refresh()['sync']);
        $this->assertSame([self::ACCOUNT], $this->xrplNode->asked);
    }

    /**
     * The account is the one in the transaction's payment record, not the one
     * configured today: an attempt quoted before the merchant changed the
     * receiving account is still paid to the old one. And each chain is asked
     * on its own node.
     */
    public function testEachTransactionIsSyncedOnTheAccountAndChainItWasQuotedFor(): void
    {
        $this->givenOrder(
            $this->transaction('tx-earlier', 'cancelled', $this->xrpQuote(1001, self::EARLIER_ACCOUNT)),
            $this->transaction('tx-xahau', 'cancelled', PaymentIntent::quote(
                type: 'xahau-xah-payment', chain: 'XAHAU', network: 'testnet', baseAsset: 'XAH', quoteCurrency: 'EUR',
                pairing: 'XAH/EUR', exchangeRate: 0.4, amountRequested: 250.0,
                destinationAccount: self::ACCOUNT, destinationTag: 1003,
            )),
            $this->transaction('tx-current', 'paid', $this->xrpQuote(1002)->withFulfillment('HASH-B', 40.0, 'C0000065000100001')),
        );
        $this->xrplNode->receives(self::EARLIER_ACCOUNT, 'HASH-OLD-ACCOUNT', 1001, '40000000', 100);
        $this->xahauNode->receives(self::ACCOUNT, 'HASH-XAHAU', 1003, '250000000', 100);

        $after = $this->refresh();

        $this->assertSame([self::EARLIER_ACCOUNT, self::ACCOUNT], $this->xrplNode->asked, 'the earlier account, and the one of today for the transaction quoted on it');
        $this->assertSame([self::ACCOUNT], $this->xahauNode->asked, 'the same address on Xahau is another account, on another node');
        $this->assertSame(['HASH-OLD-ACCOUNT'], array_column($after['transactions'][0]['payments'], 'hash'));
        $this->assertSame(['HASH-XAHAU'], array_column($after['transactions'][1]['payments'], 'hash'));
        $this->assertSame('synced', $after['sync']);
    }

    /**
     * Stellar has its own sync, table and source (Horizon, not a JSON-RPC
     * node); the refresh goes through it for a Stellar attempt.
     */
    public function testAStellarTransactionIsSyncedThroughHorizon(): void
    {
        $this->givenOrder($this->transaction('tx-stellar', 'cancelled', PaymentIntent::quote(
            type: 'stellar-xlm-payment', chain: 'STELLAR', network: 'testnet', baseAsset: 'XLM', quoteCurrency: 'EUR',
            pairing: 'XLM/EUR', exchangeRate: 0.25, amountRequested: 400.0,
            destinationAccount: self::STELLAR_ACCOUNT, destinationTag: 1001,
        )));

        $after = $this->refresh();

        $this->assertSame('synced', $after['sync']);
        $this->assertCount(1, $this->horizon->asked);
        $this->assertStringContainsString('/accounts/' . self::STELLAR_ACCOUNT . '/payments', $this->horizon->asked[0]);
        $this->assertSame([], $this->xrplNode->asked);

        $this->horizon->down = true;
        $this->assertSame('throttled', $this->refresh()['sync'], 'Stellar is throttled like the others');
    }

    /**
     * The list still comes back, from what is stored, and says that it may
     * not be current. One account failing is enough to say so.
     */
    public function testANodeThatDoesNotAnswerLeavesTheStoredRowsAndTheAnswerSaysSo(): void
    {
        $this->givenOrder(
            $this->transaction('tx-earlier', 'cancelled', $this->xrpQuote(1001, self::EARLIER_ACCOUNT)),
            $this->transaction('tx-paid', 'paid', $this->xrpQuote(1002)->withFulfillment('HASH-STORED', 40.0, 'C0000064000100001')),
        );
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-STORED', 1002, '40000000', 100);
        $this->xrplTable->saveTransactions($this->asStored($this->xrplNode));
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-NOT-FETCHED', 1002, '40000000', 101);
        $this->xrplNode->receives(self::EARLIER_ACCOUNT, 'HASH-FETCHED', 1001, '40000000', 102);

        $this->xrplNode->downFor = [self::ACCOUNT];
        $oneDown = $this->refresh();

        $this->assertSame('failed', $oneDown['sync'], 'one account without an answer: the list may lack payments');
        $this->assertSame(['HASH-FETCHED'], array_column($oneDown['transactions'][0]['payments'], 'hash'), 'the account that answered is current');
        $this->assertSame(['HASH-STORED'], array_column($oneDown['transactions'][1]['payments'], 'hash'), 'the other one shows what was stored');
    }

    public function testWithEveryNodeDownTheAnswerIsTheStoredListAndFailed(): void
    {
        $this->givenOrder($this->transaction('tx-paid', 'paid', $this->xrpQuote(1001)->withFulfillment('HASH-STORED', 40.0, 'C0000064000100001')));
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-STORED', 1001, '40000000', 100);
        $this->xrplTable->saveTransactions($this->asStored($this->xrplNode));
        $this->xrplNode->down = true;

        $after = $this->refresh();

        $this->assertSame('failed', $after['sync']);
        $this->assertSame(['HASH-STORED'], array_column($after['transactions'][0]['payments'], 'hash'));
    }

    /**
     * "Success" without a list of transactions is not "nothing new": since
     * core 0.13 the sync refuses such an answer, and the card must not show
     * the stored list as if the ledger had confirmed it.
     */
    public function testANodeThatReportsSuccessWithoutAListIsAFailedSync(): void
    {
        $this->givenOrder($this->transaction('tx-paid', 'paid', $this->xrpQuote(1001)->withFulfillment('HASH-STORED', 40.0, 'C0000064000100001')));
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-STORED', 1001, '40000000', 100);
        $this->xrplTable->saveTransactions($this->asStored($this->xrplNode));
        $this->xrplNode->withoutList = true;

        $after = $this->refresh();

        $this->assertSame([self::ACCOUNT], $this->xrplNode->asked);
        $this->assertSame('failed', $after['sync']);
        $this->assertSame(['HASH-STORED'], array_column($after['transactions'][0]['payments'], 'hash'));
    }

    /**
     * A second click within the throttle's interval asks no node: what is
     * stored is seconds old. That is no failure and gets no notice.
     */
    public function testWithinTheThrottleTheStoredRowsAreAnsweredWithoutAskingTheNode(): void
    {
        $this->givenOrder($this->transaction('tx-cancelled', 'cancelled', $this->xrpQuote(1001)));
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-LATE', 1001, '40000000', 100);

        $first = $this->refresh();
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-SECONDS-LATER', 1001, '40000000', 101);
        $second = $this->refresh();

        $this->assertSame('synced', $first['sync']);
        $this->assertSame('throttled', $second['sync']);
        $this->assertSame([self::ACCOUNT], $this->xrplNode->asked, 'one request for two clicks');
        $this->assertSame(['HASH-LATE'], array_column($second['transactions'][0]['payments'], 'hash'), 'what the first click stored');
    }

    /**
     * The throttle is marked before the node is asked, so that a node which
     * is down is not asked harder than one that answers. The click after a
     * failed one is therefore "throttled", not "failed" again: the card keeps
     * the notice it shows, see the component.
     */
    public function testTheClickAfterAFailedOneIsThrottled(): void
    {
        $this->givenOrder($this->transaction('tx-cancelled', 'cancelled', $this->xrpQuote(1001)));
        $this->xrplNode->down = true;

        $this->assertSame('failed', $this->refresh()['sync']);
        $this->assertSame('throttled', $this->refresh()['sync']);
        $this->assertCount(1, $this->xrplNode->asked);
    }

    /**
     * As the list answers them: an order of another payment method has
     * nothing to fetch and nothing to show; an unknown order is 404; and a
     * record that cannot be read names no account, so it is not synced and
     * does not keep the others from being.
     */
    public function testAnOrderWithoutALedgerDirectTransactionAnswersAnEmptyListAndAsksNoNode(): void
    {
        $this->givenOrder($this->transaction('tx-invoice', 'open', null));
        $this->stateHandler->expects($this->never())->method($this->anything());

        $this->assertSame(['transactions' => [], 'sync' => 'synced'], $this->refresh());
        $this->assertSame([], $this->xrplNode->asked);
    }

    public function testAnUnknownOrderIs404(): void
    {
        $response = $this->controller(null)->refresh(self::ORDER_ID, $this->context);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'order_not_found'], json_decode((string) $response->getContent(), true));
        $this->assertSame([], $this->xrplNode->asked);
    }

    public function testAnUnreadableRecordIsNotSyncedAndTheOthersAre(): void
    {
        $broken = $this->transaction('tx-broken', 'cancelled', null);
        $broken->setCustomFields([OrderTransactionService::CUSTOM_FIELDS_KEY => ['schema_version' => 99]]);
        $this->givenOrder($broken, $this->transaction('tx-good', 'cancelled', $this->xrpQuote(1002)));
        $this->xrplNode->receives(self::ACCOUNT, 'HASH-GOOD', 1002, '40000000', 100);

        $after = $this->refresh();

        $this->assertTrue($after['transactions'][0]['unreadable']);
        $this->assertSame(['HASH-GOOD'], array_column($after['transactions'][1]['payments'], 'hash'));
        $this->assertSame([self::ACCOUNT], $this->xrplNode->asked);
    }

    /**
     * @return array<string, mixed>
     */
    private function list(): array
    {
        return json_decode((string) $this->controller($this->order)->payments(self::ORDER_ID, $this->context)->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function refresh(): array
    {
        $response = $this->controller($this->order)->refresh(self::ORDER_ID, $this->context);
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * One controller per test, so that two requests share the tables and the throttle as two clicks do.
     */
    private function controller(?OrderEntity $order): ReceivedPaymentsController
    {
        if ($this->controller !== null) {
            return $this->controller;
        }

        $orders = $this->createStub(EntityRepository::class);
        $orders->method('search')->willReturnCallback(fn (): EntitySearchResult => new EntitySearchResult(
            'order',
            $order === null ? 0 : 1,
            new OrderCollection($order === null ? [] : [$order]),
            null,
            new Criteria(),
            $this->context
        ));

        // What PaymentStateService reloads after it has set a state: the transaction as it is now.
        $orderTransactions = $this->createStub(EntityRepository::class);
        $orderTransactions->method('search')->willReturnCallback(function (Criteria $criteria) use ($order): EntitySearchResult {
            $wanted = array_values(array_filter(
                iterator_to_array($order?->getTransactions() ?? new OrderTransactionCollection(), false),
                static fn (OrderTransactionEntity $transaction): bool => \in_array($transaction->getId(), $criteria->getIds(), true)
            ));

            return new EntitySearchResult('order_transaction', \count($wanted), new OrderTransactionCollection($wanted), null, $criteria, $this->context);
        });
        $httpFactory = new HttpFactory();
        $logger = new NullLogger();
        $prices = new PriceService(new StubHttpClient(), $httpFactory, $logger);
        $config = new FakeConfigProvider(destinationAccount: self::ACCOUNT);
        $cache = new InMemoryCache();
        $stellarTable = new InMemoryStellarPaymentRepository();
        $settlementPolicy = new SettlementPolicy();

        $service = new OrderTransactionService(
            $orders,
            $orderTransactions,
            $this->createStub(EntityRepository::class),
            new PaymentIntentService($prices, new DestinationTagService($this->xrplTable), $config),
            new SyncService(new XrplClient($this->xrplNode, $httpFactory, $httpFactory), $this->xrplTable, $logger),
            new StellarPaymentIntentService($prices, new MemoIdService($stellarTable), $config),
            new StellarSyncService(new HorizonClient($this->horizon, $httpFactory), $stellarTable, $logger),
            new SyncThrottle($cache, $logger),
            $logger,
            new PaymentIntentService($prices, new DestinationTagService($this->xahauTable), $config, null, 'XAHAU'),
            new SyncService(new XrplClient($this->xahauNode, $httpFactory, $httpFactory, 'XAHAU'), $this->xahauTable, $logger, null, 'XAHAU'),
            new SyncThrottle($cache, $logger, chain: 'XAHAU')
        );

        return $this->controller = new ReceivedPaymentsController(
            $service,
            $settlementPolicy,
            new PaymentStateService($this->stateHandler, $service, $settlementPolicy, $logger)
        );
    }

    /** The order with these transactions, oldest first. */
    private function givenOrder(OrderTransactionEntity ...$transactions): void
    {
        $this->order = new OrderEntity();
        $this->order->setId(self::ORDER_ID);
        $this->order->setUniqueIdentifier(self::ORDER_ID);
        $this->order->setTransactions(new OrderTransactionCollection($transactions));
    }

    /**
     * @param PaymentIntent|null $intent null: a transaction of another payment method, without a record
     */
    private function transaction(string $id, string $state, ?PaymentIntent $intent): OrderTransactionEntity
    {
        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setTechnicalName($state);
        $stateEntity->setTranslated(['name' => ucfirst($state)]);

        $transaction = new OrderTransactionEntity();
        $transaction->setId($id);
        $transaction->setUniqueIdentifier($id);
        $transaction->setStateMachineState($stateEntity);

        if ($intent !== null) {
            $transaction->setCustomFields([OrderTransactionService::CUSTOM_FIELDS_KEY => $intent->toArray()]);
        }

        return $transaction;
    }

    private function xrpQuote(int $tag, string $account = self::ACCOUNT): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 40.0,
            destinationAccount: $account, destinationTag: $tag,
        );
    }

    /**
     * What a sync would have stored of the node's payments so far: the table before the test's refresh.
     *
     * @return list<\Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction>
     */
    private function asStored(LedgerNodeStub $node): array
    {
        $stored = [];

        foreach ($node->onLedger as $payments) {
            foreach ($payments as $raw) {
                $stored[] = new \Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction(
                    network: 'testnet', ledgerIndex: (string) $raw['tx']['ledger_index'], hash: $raw['tx']['hash'], ctid: $raw['tx']['ctid'],
                    account: $raw['tx']['Account'], destination: $raw['tx']['Destination'], destinationTag: $raw['tx']['DestinationTag'],
                    date: $raw['tx']['date'], meta: $raw['meta'], tx: $raw['tx'],
                );
            }
        }

        return $stored;
    }
}
