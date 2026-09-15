<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Exception;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\Http\StubHttpClient;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;

/**
 * Adapter-level tests: the core services are the real ones, only the edges
 * the platform owns are stubbed (HTTP, the transaction repository, Shopware's
 * repositories). What is asserted here is the adapter's job — that an order
 * is quoted in the right asset and that the resulting PaymentIntent is
 * written to, and read back from, the order transaction's customFields.
 */
class OrderTransactionServiceTest extends TestCase
{
    private const DESTINATION_ACCOUNT = 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr';

    /** Sequence 0 run through the core's fixed permutation. */
    private const FIRST_DESTINATION_TAG = 114729;

    private const TX_ID = 'order-transaction-id';

    private Context $context;

    private EntityRepository $orderTransactionRepository;

    private XrplTransactionRepositoryInterface $transactionRepository;

    private StubHttpClient $httpClient;

    private InMemoryCache $throttleStore;

    protected function setUp(): void
    {
        $this->context = new Context(new SystemSource());
        $this->orderTransactionRepository = Mockery::mock(EntityRepository::class);
        $this->transactionRepository = Mockery::mock(XrplTransactionRepositoryInterface::class);
        $this->httpClient = new StubHttpClient(2.5);
        $this->throttleStore = new InMemoryCache();
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testPrepareStoresAQuoteOnTheOrderTransaction(): void
    {
        $this->transactionRepository->shouldReceive('nextDestinationTagSequence')->once()->andReturn(0);

        $orderTransaction = $this->givenOrderTransaction(PaymentMethodInstaller::XRP_PAYMENT_ID);
        $this->expectUpsertCapturing($customFields);

        $this->createService()->prepareOrderTransactionForXrpl(
            $this->givenOrder(),
            $orderTransaction,
            $this->context
        );

        $intent = $customFields[0]['customFields'][OrderTransactionService::CUSTOM_FIELDS_KEY];

        $this->assertSame(PaymentIntent::SCHEMA_VERSION, $intent['schema_version']);
        $this->assertSame('xrp-payment', $intent['type']);
        $this->assertSame('XRPL', $intent['chain']);
        $this->assertSame('testnet', $intent['network']);
        $this->assertSame('XRP', $intent['base_asset']);
        $this->assertSame('EUR', $intent['quote_currency']);
        $this->assertSame('XRP/EUR', $intent['pairing']);
        $this->assertSame(2.5, $intent['exchange_rate']);
        $this->assertSame(40.0, $intent['amount_requested']); // 100.00 EUR / 2.5
        $this->assertSame(self::DESTINATION_ACCOUNT, $intent['destination_account']);
        $this->assertSame(self::FIRST_DESTINATION_TAG, $intent['destination_tag']);
        $this->assertGreaterThan(time(), $intent['expiry']);
        $this->assertNull($intent['hash']);
        $this->assertNull($intent['amount_paid']);
    }

    /**
     * Stablecoins carry an XRPL issued-currency amount, not a bare number —
     * the shape the ledger needs to route the payment to the right issuer.
     */
    public function testPrepareQuotesStablecoinsAsAnIssuedCurrencyAmount(): void
    {
        $this->transactionRepository->shouldReceive('nextDestinationTagSequence')->once()->andReturn(0);

        $orderTransaction = $this->givenOrderTransaction(PaymentMethodInstaller::RLUSD_PAYMENT_ID);
        $this->expectUpsertCapturing($customFields);

        $this->createService()->prepareOrderTransactionForXrpl(
            $this->givenOrder(),
            $orderTransaction,
            $this->context
        );

        $intent = $customFields[0]['customFields'][OrderTransactionService::CUSTOM_FIELDS_KEY];

        $this->assertSame('rlusd-payment', $intent['type']);
        $this->assertSame('RLUSD', $intent['base_asset']);
        $this->assertIsArray($intent['amount_requested']);
        $this->assertSame('40.00', $intent['amount_requested']['value']);
        $this->assertSame('rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV', $intent['amount_requested']['issuer']);
    }

    /**
     * A second attempt on the same order must not hand the customer a new
     * destination tag: they may already be looking at the old one, or have a
     * payment in flight against it.
     */
    public function testPrepareKeepsTheDestinationTagOfAnExistingQuote(): void
    {
        $this->transactionRepository->shouldReceive('nextDestinationTagSequence')->never();

        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::XRP_PAYMENT_ID,
            $this->givenStoredIntent()->toArray()
        );
        $this->expectUpsertCapturing($customFields);

        $this->createService()->prepareOrderTransactionForXrpl(
            $this->givenOrder(),
            $orderTransaction,
            $this->context
        );

        $intent = $customFields[0]['customFields'][OrderTransactionService::CUSTOM_FIELDS_KEY];

        $this->assertSame(4294967295, $intent['destination_tag']);
        $this->assertSame(40.0, $intent['amount_requested'], 'the price is still re-quoted');
    }

    public function testPrepareRejectsAnUnknownPaymentMethod(): void
    {
        $orderTransaction = $this->givenOrderTransaction(Uuid::randomHex());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported payment method');

        $this->createService()->prepareOrderTransactionForXrpl(
            $this->givenOrder(),
            $orderTransaction,
            $this->context
        );
    }

    public function testSyncRecordsTheSettlementOnTheStoredQuote(): void
    {
        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::XRP_PAYMENT_ID,
            $this->givenStoredIntent()->toArray()
        );

        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')
            ->with(self::DESTINATION_ACCOUNT, 'testnet')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransactions')
            ->with(self::DESTINATION_ACCOUNT, 4294967295)
            ->andReturn([$this->givenLedgerTransaction(['delivered_amount' => '40000000'])]);

        $this->expectUpsertCapturing($customFields);

        $fulfilledIntent = $this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context);

        $this->assertNotNull($fulfilledIntent);
        $this->assertSame('HASH', $fulfilledIntent->hash);
        $this->assertSame('CTID', $fulfilledIntent->ctid);
        // 40000000 drops is 40 XRP — the adapter no longer converts this itself.
        $this->assertSame(40.0, $fulfilledIntent->amountPaid);

        $intent = $customFields[0]['customFields'][OrderTransactionService::CUSTOM_FIELDS_KEY];
        $this->assertSame('HASH', $intent['hash']);
        $this->assertSame(40.0, $intent['amount_paid']);
    }

    /**
     * Not every transaction carrying this destination tag delivered money —
     * an EscrowCreate to the same account has no delivered amount, and the
     * order must stay unpaid rather than settle on a null.
     */
    public function testSyncIgnoresATransactionThatDeliveredNothing(): void
    {
        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::XRP_PAYMENT_ID,
            $this->givenStoredIntent()->toArray()
        );

        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')
            ->with(self::DESTINATION_ACCOUNT, 'testnet')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransactions')
            ->andReturn([$this->givenLedgerTransaction([])]);

        $this->orderTransactionRepository->shouldReceive('upsert')->never();

        $this->assertNull($this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context));
    }

    /**
     * A tag can carry a payment in the other asset class — someone sends RLUSD
     * against an XRP quote. Picking by row order hands that one to
     * withFulfillment(), which rejects the shape outright: the sync aborts and
     * the order stays unpaid forever, with the real payment sitting right there
     * unexamined. The core picks by asset class instead, so the order the
     * candidates arrive in must not matter.
     *
     */
    #[DataProvider('strayPaymentOrderings')]
    public function testAStrayPaymentInAnotherAssetClassIsSkipped(bool $strayIsNewer): void
    {
        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::XRP_PAYMENT_ID,
            $this->givenStoredIntent()->toArray()
        );

        $xrpPayment = $this->givenLedgerTransaction(
            ['delivered_amount' => '40000000'],
            'HASH_XRP',
            $strayIsNewer ? '1000' : '2000'
        );
        $strayTokenPayment = $this->givenLedgerTransaction(
            ['delivered_amount' => ['currency' => 'RLUSD', 'value' => '40', 'issuer' => 'rIssuer']],
            'HASH_RLUSD',
            $strayIsNewer ? '2000' : '1000'
        );

        // Newest first, as the port promises.
        $candidates = $strayIsNewer ? [$strayTokenPayment, $xrpPayment] : [$xrpPayment, $strayTokenPayment];

        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')
            ->with(self::DESTINATION_ACCOUNT, 'testnet')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransactions')->andReturn($candidates);
        $this->expectUpsertCapturing($customFields);

        $fulfilledIntent = $this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context);

        $this->assertNotNull($fulfilledIntent);
        $this->assertSame('HASH_XRP', $fulfilledIntent->hash);
        $this->assertSame(40.0, $fulfilledIntent->amountPaid);
    }

    public static function strayPaymentOrderings(): array
    {
        return ['stray payment is newer' => [true], 'stray payment is older' => [false]];
    }

    /**
     * A token of the right kind but from the wrong issuer is deliberately still
     * recorded — the customer did pay, and their wallet shows a successful
     * transaction — but it settles nothing and the full amount stays due. That
     * is what lets the payment page say "wrong token" instead of showing
     * nothing at all.
     */
    public function testAPaymentFromTheWrongIssuerIsRecordedButSettlesNothing(): void
    {
        $storedIntent = $this->givenStoredStablecoinIntent();
        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::RLUSD_PAYMENT_ID,
            $storedIntent->toArray()
        );

        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')
            ->with(self::DESTINATION_ACCOUNT, 'testnet')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransactions')->andReturn([
            $this->givenLedgerTransaction([
                'delivered_amount' => [
                    'currency' => '524C555344000000000000000000000000000000',
                    'value' => '40.00',
                    'issuer' => 'rSomeoneElsesIssuerAccount',
                ],
            ], 'HASH_WRONG_ISSUER'),
        ]);
        $this->expectUpsertCapturing($customFields);

        $fulfilledIntent = $this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context);

        $this->assertNotNull($fulfilledIntent, 'the payment attempt is recorded');
        $this->assertSame('HASH_WRONG_ISSUER', $fulfilledIntent->hash);

        $settlementPolicy = new SettlementPolicy();
        $this->assertFalse($settlementPolicy->isSettled($fulfilledIntent));
        $this->assertTrue($settlementPolicy->isWrongAsset($fulfilledIntent));
        // The delivered amount stays visible, so the page can name the wrong token.
        $this->assertSame('rSomeoneElsesIssuerAccount', $fulfilledIntent->amountPaid['issuer']);
        $this->assertSame('40.00', $fulfilledIntent->amountPaid['value']);
        // '40', not '40.00': the core reports a plain decimal without trailing zeros.
        $this->assertSame('40', $settlementPolicy->shortfall($fulfilledIntent), 'the full amount is still due');
    }

    /**
     * Two payments in the quoted asset add up — the customer who sends the
     * shortfall after a first, short payment settles the order. The intent
     * records the newest contributing transaction.
     */
    public function testTwoPartialPaymentsInTheQuotedAssetAddUp(): void
    {
        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::XRP_PAYMENT_ID,
            $this->givenStoredIntent()->toArray()
        );

        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')
            ->with(self::DESTINATION_ACCOUNT, 'testnet')->andReturn(null);
        // Newest first, as the port promises.
        $this->transactionRepository->shouldReceive('findTransactions')->andReturn([
            $this->givenLedgerTransaction(['delivered_amount' => '15000000'], 'HASH_TOP_UP', '2000'),
            $this->givenLedgerTransaction(['delivered_amount' => '25000000'], 'HASH_FIRST', '1000'),
        ]);
        $this->expectUpsertCapturing($customFields);

        $fulfilledIntent = $this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context);

        $this->assertNotNull($fulfilledIntent);
        $this->assertSame(40.0, $fulfilledIntent->amountPaid, '25 + 15 XRP');
        $this->assertSame('HASH_TOP_UP', $fulfilledIntent->hash, 'the newest contributing transaction');

        $intent = $customFields[0]['customFields'][OrderTransactionService::CUSTOM_FIELDS_KEY];
        $this->assertSame(40.0, $intent['amount_paid']);
    }

    /**
     * Once something in the quoted asset has arrived, only that counts: a
     * payment from the wrong issuer on the same tag is neither added nor
     * shown as the fulfillment any more.
     */
    public function testAPaymentInTheQuotedAssetOutranksOneFromTheWrongIssuer(): void
    {
        $storedIntent = $this->givenStoredStablecoinIntent();
        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::RLUSD_PAYMENT_ID,
            $storedIntent->toArray()
        );

        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')
            ->with(self::DESTINATION_ACCOUNT, 'testnet')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransactions')->andReturn([
            $this->givenLedgerTransaction([
                'delivered_amount' => [
                    'currency' => '524C555344000000000000000000000000000000',
                    'value' => '40.00',
                    'issuer' => 'rSomeoneElsesIssuerAccount',
                ],
            ], 'HASH_WRONG_ISSUER', '2000'),
            $this->givenLedgerTransaction([
                'delivered_amount' => [
                    'currency' => '524C555344000000000000000000000000000000',
                    'value' => '10.00',
                    'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
                ],
            ], 'HASH_RIGHT_ISSUER', '1000'),
        ]);
        $this->expectUpsertCapturing($customFields);

        $fulfilledIntent = $this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context);

        $this->assertNotNull($fulfilledIntent);
        $this->assertSame('HASH_RIGHT_ISSUER', $fulfilledIntent->hash);
        $this->assertSame('10', $fulfilledIntent->amountPaid['value']);

        $settlementPolicy = new SettlementPolicy();
        $this->assertFalse($settlementPolicy->isWrongAsset($fulfilledIntent));
        $this->assertSame('30', $settlementPolicy->shortfall($fulfilledIntent));
    }

    /**
     * The payment page and the status endpoint pass throttled: true. Inside
     * the interval the node is asked once per receiving account, however
     * many customers are polling; matching still runs against the table.
     */
    public function testAThrottledSyncAsksTheNodeOncePerWindow(): void
    {
        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::XRP_PAYMENT_ID,
            $this->givenStoredIntent()->toArray()
        );
        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransactions')->times(3)->andReturn([]);

        $service = $this->createService();
        $service->syncOrderTransactionWithXrpl($orderTransaction, $this->context, throttled: true);
        $service->syncOrderTransactionWithXrpl($orderTransaction, $this->context, throttled: true);

        $this->assertSame(1, $this->httpClient->ledgerRequests);

        // The window has passed: the next call syncs again.
        $this->throttleStore->advance(6);
        $service->syncOrderTransactionWithXrpl($orderTransaction, $this->context, throttled: true);

        $this->assertSame(2, $this->httpClient->ledgerRequests);
    }

    /**
     * The scheduled task is the safety net and never throttled.
     */
    public function testAnUnthrottledSyncAlwaysAsksTheNode(): void
    {
        $orderTransaction = $this->givenOrderTransaction(
            PaymentMethodInstaller::XRP_PAYMENT_ID,
            $this->givenStoredIntent()->toArray()
        );
        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransactions')->andReturn([]);

        $service = $this->createService();
        $service->syncOrderTransactionWithXrpl($orderTransaction, $this->context);
        $service->syncOrderTransactionWithXrpl($orderTransaction, $this->context);

        $this->assertSame(2, $this->httpClient->ledgerRequests);
    }

    public function testSyncWithoutAStoredQuoteReturnsNull(): void
    {
        $orderTransaction = $this->givenOrderTransaction(PaymentMethodInstaller::XRP_PAYMENT_ID);

        $this->orderTransactionRepository->shouldReceive('upsert')->never();

        $this->assertNull($this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context));
    }

    private function createService(): OrderTransactionService
    {
        $httpClient = $this->httpClient;
        $httpFactory = new HttpFactory();
        $logger = new NullLogger();

        $configProvider = new ShopwareConfigProvider(
            ConfigurationServiceMock::createInstance(Fixtures::getStaticConfiguration())
        );

        $paymentIntentService = new PaymentIntentService(
            new PriceService($httpClient, $httpFactory, $logger),
            new DestinationTagService($this->transactionRepository),
            $configProvider
        );

        $syncService = new SyncService(
            new XrplClient($httpClient, $httpFactory, $httpFactory),
            $this->transactionRepository,
            $logger
        );

        return new OrderTransactionService(
            Mockery::mock(EntityRepository::class),
            $this->orderTransactionRepository,
            $this->givenCurrencyRepository(),
            $paymentIntentService,
            $syncService,
            new SyncThrottle($this->throttleStore, $logger),
            $logger
        );
    }

    private function givenStoredIntent(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.0,
            amountRequested: 50.0,
            destinationAccount: self::DESTINATION_ACCOUNT,
            // Deliberately at the top of XRPL's unsigned 32-bit tag range:
            // the core issues tags there, so the adapter must round-trip them.
            destinationTag: 4294967295,
            expiry: time() + 300,
        );
    }

    private function givenStoredStablecoinIntent(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'EUR',
            pairing: 'RLUSD/EUR',
            exchangeRate: 2.5,
            amountRequested: [
                'currency' => '524C555344000000000000000000000000000000',
                'value' => '40.00',
                'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
            ],
            destinationAccount: self::DESTINATION_ACCOUNT,
            destinationTag: 4294967295,
            expiry: time() + 300,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function givenLedgerTransaction(
        array $meta,
        string $hash = 'HASH',
        string $ledgerIndex = '1000'
    ): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet',
            ledgerIndex: $ledgerIndex,
            hash: $hash,
            ctid: 'CTID',
            account: 'rSenderAccount',
            destination: self::DESTINATION_ACCOUNT,
            destinationTag: 4294967295,
            date: 0,
            meta: $meta,
            tx: [],
        );
    }

    private function givenOrder(): OrderEntity
    {
        $order = Mockery::mock(OrderEntity::class);
        $order->shouldReceive('getId')->andReturn(Uuid::randomHex());
        $order->shouldReceive('getCurrencyId')->andReturn(Uuid::randomHex());
        $order->shouldReceive('getAmountTotal')->andReturn(100.0);

        return $order;
    }

    /**
     * @param array<string, mixed>|null $storedIntent
     */
    private function givenOrderTransaction(string $paymentMethodId, ?array $storedIntent = null): OrderTransactionEntity
    {
        $paymentMethod = Mockery::mock(PaymentMethodEntity::class);
        $paymentMethod->shouldReceive('getId')->andReturn($paymentMethodId);

        $customFields = $storedIntent === null
            ? []
            : [OrderTransactionService::CUSTOM_FIELDS_KEY => $storedIntent];

        $orderTransaction = Mockery::mock(OrderTransactionEntity::class);
        $orderTransaction->shouldReceive('getId')->andReturn(self::TX_ID);
        $orderTransaction->shouldReceive('getPaymentMethod')->andReturn($paymentMethod);
        $orderTransaction->shouldReceive('getCustomFields')->andReturn($customFields);
        $orderTransaction->shouldReceive('setCustomFields');

        return $orderTransaction;
    }

    private function givenCurrencyRepository(): EntityRepository
    {
        $currency = Mockery::mock(CurrencyEntity::class);
        $currency->shouldReceive('getIsoCode')->andReturn('EUR');

        $collection = Mockery::mock(EntityCollection::class);
        $collection->shouldReceive('first')->andReturn($currency);

        $searchResult = Mockery::mock(EntitySearchResult::class);
        $searchResult->shouldReceive('getEntities')->andReturn($collection);

        $currencyRepository = Mockery::mock(EntityRepository::class);
        $currencyRepository->shouldReceive('search')->andReturn($searchResult);

        return $currencyRepository;
    }

    private function expectUpsertCapturing(&$payload): void
    {
        $this->orderTransactionRepository->shouldReceive('upsert')
            ->once()
            ->with(Mockery::capture($payload), Mockery::any());
    }
}
