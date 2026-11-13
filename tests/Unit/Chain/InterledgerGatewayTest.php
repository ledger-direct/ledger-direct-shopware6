<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Chain;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Chain\InterledgerGateway;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Core\Testing\RecordingLogger;
use Hardcastle\LedgerDirect\Core\Payment\AssetNotAcceptedException;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsException;
use Hardcastle\LedgerDirect\Interledger\IncomingPaymentService;
use Hardcastle\LedgerDirect\Interledger\ReceiptSyncService;
use Hardcastle\LedgerDirect\Interledger\Testing\FakeOpenPayments;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The gateway decides whether Interledger can be used at all, and builds
 * the package's services when it can. Nothing of the package is touched
 * while it cannot — that is what makes the package optional.
 */
class InterledgerGatewayTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private RecordingLogger $logger;

    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->cache = new InMemoryCache();
    }

    public function testWithoutTheCardFilledInNothingIsAvailable(): void
    {
        $gateway = $this->gateway(Fixtures::getStaticConfiguration());

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('', $gateway->walletAddress());
        $this->assertNull($gateway->walletAssetCode(), 'and the checkout filter therefore offers nothing');
    }

    public function testACompleteCardMakesItAvailable(): void
    {
        $gateway = $this->gateway(Fixtures::getStaticInterledgerConfiguration());

        $this->assertTrue($gateway->isAvailable());
        $this->assertSame(Fixtures::INTERLEDGER_WALLET, $gateway->walletAddress());
    }

    /**
     * The switch decides whether new payments are taken. The payments already
     * requested must stay readable, so the services are still built — and the
     * package still refuses a new quote, because the asset is not enabled.
     */
    public function testASwitchedOffChainTakesNothingNewButStillReadsItsPayments(): void
    {
        $switchedOff = ['LedgerDirect.config.interledgerEnabled' => false] + Fixtures::getStaticInterledgerConfiguration();
        $gateway = $this->gateway($switchedOff, new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2));

        $this->assertFalse($gateway->isAvailable());
        $this->assertSame('', $gateway->walletAddress());
        $this->assertNull($gateway->walletAssetCode(), 'the checkout filter offers nothing');
        $this->assertInstanceOf(ReceiptSyncService::class, $gateway->receiptSync());

        $this->expectException(AssetNotAcceptedException::class);
        $gateway->incomingPaymentService()->quoteForOrder(19.99, 'EUR', 'EUR');
    }

    public function testAWalletAddressWrittenAsAPaymentPointerIsUsedAsItsUrl(): void
    {
        $configuration = Fixtures::getStaticInterledgerConfiguration();
        $configuration['LedgerDirect.config.interledgerTestnetDestinationAccount'] = '$ilp.example.test/merchant';

        $this->assertSame('https://ilp.example.test/merchant', $this->gateway($configuration)->walletAddress());
    }

    public function testAKeyInTheWrongShapeIsNotUsedAndIsLogged(): void
    {
        $configuration = Fixtures::getStaticInterledgerConfiguration();
        // The PEM itself, not its base64: what the SDK's signer cannot work with.
        $configuration['LedgerDirect.config.interledgerPrivateKey'] = "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----";

        $this->assertFalse($this->gateway($configuration)->isAvailable());
        $this->assertSame('warning', $this->logger->records()[0]['level']);
    }

    public function testTheWalletsAssetIsReadOnceAndThenCached(): void
    {
        $wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $gateway = $this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet);

        $this->assertSame('EUR', $gateway->walletAssetCode());
        $this->assertSame('EUR', $this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet)->walletAssetCode());
        $this->assertSame(1, $wallet->addressReads, 'the checkout asks on every render; the wallet does not');
    }

    public function testAWalletThatCannotBeReachedHasNoAssetAndIsLogged(): void
    {
        $wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $wallet->failWith = new OpenPaymentsException('503 Service Unavailable');

        $this->assertNull($this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet)->walletAssetCode());
        $this->assertSame('warning', $this->logger->records()[0]['level']);
    }

    /**
     * The checkout's payment-method list asks for every Interledger method, so
     * a wallet that is down must be asked once and not once per method.
     */
    public function testAnUnreachableWalletIsRememberedForAMoment(): void
    {
        $wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $wallet->failWith = new OpenPaymentsException('503 Service Unavailable');

        self::assertNull($this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet)->walletAssetCode());
        self::assertNull($this->gateway(Fixtures::getStaticInterledgerConfiguration(), $wallet)->walletAssetCode());

        // Two gateways stand for two requests; they share the cache, and only a call that
        // actually asked the wallet logs. (The fake's own counter sees successful reads only.)
        $attempts = array_filter(
            $this->logger->records(),
            static fn (array $record): bool => str_contains($record['message'], 'could not read the Interledger wallet'),
        );
        self::assertCount(1, $attempts, 'the second call reads the cache, not the wallet');
    }

    public function testTheServicesComeOutOfThePackageWhenItIsConfigured(): void
    {
        $gateway = $this->gateway(Fixtures::getStaticInterledgerConfiguration(), new FakeOpenPayments());

        $this->assertInstanceOf(IncomingPaymentService::class, $gateway->incomingPaymentService());
        $this->assertInstanceOf(ReceiptSyncService::class, $gateway->receiptSync());
        $this->assertSame($gateway->receiptSync(), $gateway->receiptSync(), 'built once per request');
    }

    public function testAskingForTheServicesWithoutConfigurationSaysSo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not configured/');

        $this->gateway(Fixtures::getStaticConfiguration())->incomingPaymentService();
    }

    private function gateway(array $configuration, ?FakeOpenPayments $wallet = null): InterledgerGateway
    {
        $configurationService = ConfigurationServiceMock::createInstance($configuration);

        $gateway = new InterledgerGateway(
            $configurationService,
            new ShopwareConfigProvider($configurationService),
            new FakeHttpClient(),
            new HttpFactory(),
            Mockery::mock(Connection::class),
            $this->cache,
            $this->logger,
        );

        if ($wallet !== null) {
            $gateway->useWallet($wallet);
        }

        return $gateway;
    }
}
