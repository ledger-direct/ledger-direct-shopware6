<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Exception;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\Http\StubHttpClient;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
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

    protected function setUp(): void
    {
        $this->context = new Context(new SystemSource());
        $this->orderTransactionRepository = Mockery::mock(EntityRepository::class);
        $this->transactionRepository = Mockery::mock(XrplTransactionRepositoryInterface::class);
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

        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransaction')
            ->with(self::DESTINATION_ACCOUNT, 4294967295)
            ->andReturn($this->givenLedgerTransaction(['delivered_amount' => '40000000']));

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

        $this->transactionRepository->shouldReceive('getLastSyncedLedgerIndex')->andReturn(null);
        $this->transactionRepository->shouldReceive('findTransaction')
            ->andReturn($this->givenLedgerTransaction([]));

        $this->orderTransactionRepository->shouldReceive('upsert')->never();

        $this->assertNull($this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context));
    }

    public function testSyncWithoutAStoredQuoteReturnsNull(): void
    {
        $orderTransaction = $this->givenOrderTransaction(PaymentMethodInstaller::XRP_PAYMENT_ID);

        $this->orderTransactionRepository->shouldReceive('upsert')->never();

        $this->assertNull($this->createService()->syncOrderTransactionWithXrpl($orderTransaction, $this->context));
    }

    private function createService(array $ledgerTransactions = []): OrderTransactionService
    {
        $httpClient = new StubHttpClient(2.5, $ledgerTransactions);
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
            $syncService
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

    /**
     * @param array<string, mixed> $meta
     */
    private function givenLedgerTransaction(array $meta): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: '1000',
            hash: 'HASH',
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
