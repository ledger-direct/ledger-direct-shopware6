<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Checkout;

use Doctrine\DBAL\Connection;
use Hardcastle\LedgerDirect\Chain\InterledgerGateway;
use Hardcastle\LedgerDirect\Checkout\PaymentMethodRouteDecorator;
use Hardcastle\LedgerDirect\Components\PaymentHandler\XrplPaymentHandler;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Testing\InMemoryCache;
use Hardcastle\LedgerDirect\Interledger\Client\OpenPaymentsException;
use Hardcastle\LedgerDirect\Interledger\Testing\FakeOpenPayments;
use Hardcastle\LedgerDirect\Core\Stellar\AccountInspector;
use Hardcastle\LedgerDirect\Core\Stellar\HorizonClient;
use Hardcastle\LedgerDirect\Core\Testing\FakeHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * The checkout offers a LedgerDirect method only while the core would quote
 * it: chain on, account set, asset enabled. Other plugins' methods pass.
 */
class PaymentMethodRouteDecoratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const OTHER_METHOD = '0190c1d8a0e24c2b9c1e1a7b1d2e3f41';

    private FakeHttpClient $horizon;

    private ?FakeOpenPayments $wallet = null;

    /** The currency the customer checks out in. */
    private string $currency = 'EUR';

    protected function setUp(): void
    {
        $this->horizon = new FakeHttpClient();
        $this->wallet = null;
    }


    public function testADisabledAssetAndAnotherPluginsMethodAreTreatedDifferently(): void
    {
        // Fixture: XRPL on, RLUSD on, USDC off.
        $methods = $this->load(Fixtures::getStaticConfiguration());

        $this->assertSame(
            [PaymentMethodInstaller::XRP_PAYMENT_ID, PaymentMethodInstaller::RLUSD_PAYMENT_ID, self::OTHER_METHOD],
            array_values($methods->getIds())
        );
    }

    public function testAChainSwitchedOffTakesAllItsMethodsOut(): void
    {
        $configuration = Fixtures::getStaticConfiguration();
        $configuration['LedgerDirect.config.xrplEnabled'] = false;

        $this->assertSame([self::OTHER_METHOD], array_values($this->load($configuration)->getIds()));
    }

    public function testWithoutAReceivingAccountNothingOfOursIsOffered(): void
    {
        $configuration = Fixtures::getStaticConfiguration();
        unset($configuration['LedgerDirect.config.xrplTestnetDestinationAccount']);

        $this->assertSame([self::OTHER_METHOD], array_values($this->load($configuration)->getIds()));
    }

    /**
     * Stellar: XLM needs nothing but the chain; an issued asset needs the
     * receiving account's trust line to its issuer, read from Horizon's
     * account record. When Horizon cannot be asked, the issued asset is not
     * offered - fail closed; XLM stays.
     */
    public function testAStellarIssuedAssetIsOfferedOnlyWithATrustLine(): void
    {
        $configuration = Fixtures::getStaticMultiChainConfiguration();
        $xlm = PaymentMethodInstaller::paymentMethodIdFor('stellar-xlm-payment');
        $usdc = PaymentMethodInstaller::paymentMethodIdFor('stellar-usdc-payment');
        $usdcIssuer = ChainCatalog::chain('STELLAR')->asset('USDC')->issuer('testnet');

        $this->horizon->queueResponse('/accounts/' . Fixtures::STELLAR_ACCOUNT, self::account([['asset_type' => 'native', 'balance' => '100.0000000']]));
        $this->assertSame([PaymentMethodInstaller::XRP_PAYMENT_ID, PaymentMethodInstaller::RLUSD_PAYMENT_ID, $xlm, self::OTHER_METHOD], array_values($this->load($configuration)->getIds()), 'no trust line: USDC is not offered, XLM is');

        $this->horizon->queueResponse('/accounts/' . Fixtures::STELLAR_ACCOUNT, self::account([
            ['asset_type' => 'native', 'balance' => '100.0000000'],
            ['asset_type' => 'credit_alphanum4', 'asset_code' => 'USDC', 'asset_issuer' => $usdcIssuer, 'balance' => '0.0000000', 'limit' => '922337203685.4775807', 'is_authorized' => true],
        ]));
        $this->assertContains($usdc, $this->load($configuration)->getIds(), 'with the trust line USDC is offered');

        $this->horizon->queueResponse('/accounts/' . Fixtures::STELLAR_ACCOUNT, new \RuntimeException('Horizon 503'));
        $ids = $this->load($configuration)->getIds();
        $this->assertNotContains($usdc, $ids, 'Horizon down: the issued asset is not offered, logged');
        $this->assertContains($xlm, $ids, 'XLM needs no trust line and stays');
    }

    /**
     * Interledger: a wallet address holds exactly one asset, so only the
     * method in that asset can be paid. The other Interledger methods are
     * installed but never offered — a merchant who moves to a wallet in
     * another currency needs no plugin update, just a new wallet address.
     */
    public function testOnlyTheInterledgerMethodInTheWalletsOwnAssetIsOffered(): void
    {
        $this->wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $eur = PaymentMethodInstaller::paymentMethodIdFor('interledger-eur-payment');
        $usd = PaymentMethodInstaller::paymentMethodIdFor('interledger-usd-payment');

        $ids = $this->load(Fixtures::getStaticInterledgerConfiguration())->getIds();

        $this->assertContains($eur, $ids, 'the wallet holds EUR');
        $this->assertNotContains($usd, $ids, 'the same wallet cannot take USD');
    }

    /**
     * The prototype converts no fiat. A EUR wallet takes EUR orders, so in a
     * USD checkout its method is not offered: quoting it would fail after the
     * customer has chosen it.
     */
    public function testAnInterledgerWalletIsNotOfferedInACurrencyItCannotBeQuotedIn(): void
    {
        $this->wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $this->currency = 'USD';

        $ids = $this->load(Fixtures::getStaticInterledgerConfiguration())->getIds();

        $this->assertNotContains(PaymentMethodInstaller::paymentMethodIdFor('interledger-eur-payment'), $ids);
        $this->assertContains(PaymentMethodInstaller::XRP_PAYMENT_ID, $ids, 'the other chains are unaffected');
    }

    /**
     * DSUSD is worth a dollar without being called one: its peg decides, not
     * its name. XRP has no peg and is priced by the oracles in any currency.
     */
    public function testAnInterledgerAssetFollowsItsPegAndAnUnpeggedOneIsOfferedInAnyCurrency(): void
    {
        $dsusd = PaymentMethodInstaller::paymentMethodIdFor('interledger-dsusd-payment');
        $xrp = PaymentMethodInstaller::paymentMethodIdFor('interledger-xrp-payment');
        $methods = fn (): PaymentMethodCollection => new PaymentMethodCollection([$this->method($dsusd), $this->method($xrp)]);

        $this->currency = 'USD';
        $this->wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'DSUSD', 2);
        $this->assertContains($dsusd, $this->loadCollection($methods(), Fixtures::getStaticInterledgerConfiguration())->getIds());

        $this->currency = 'EUR';
        $this->assertNotContains($dsusd, $this->loadCollection($methods(), Fixtures::getStaticInterledgerConfiguration())->getIds());

        $this->wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'XRP', 6);
        $this->assertContains($xrp, $this->loadCollection($methods(), Fixtures::getStaticInterledgerConfiguration())->getIds());
    }

    /**
     * A wallet that cannot be reached offers nothing — the same fail-closed
     * rule as a trust line that cannot be checked.
     */
    public function testAnUnreachableInterledgerWalletOffersNothing(): void
    {
        $this->wallet = new FakeOpenPayments(Fixtures::INTERLEDGER_WALLET, 'EUR', 2);
        $this->wallet->failWith = new OpenPaymentsException('503 Service Unavailable');

        $ids = $this->load(Fixtures::getStaticInterledgerConfiguration())->getIds();

        $this->assertNotContains(PaymentMethodInstaller::paymentMethodIdFor('interledger-eur-payment'), $ids);
        $this->assertContains(PaymentMethodInstaller::XRP_PAYMENT_ID, $ids, 'the other chains are unaffected');
    }

    /** Without the Interledger card filled in, none of its methods is offered. */
    public function testWithoutAnInterledgerWalletNoneOfItsMethodsIsOffered(): void
    {
        $ids = $this->load(Fixtures::getStaticConfiguration())->getIds();

        $this->assertNotContains(PaymentMethodInstaller::paymentMethodIdFor('interledger-eur-payment'), $ids);
        $this->assertNotContains(PaymentMethodInstaller::paymentMethodIdFor('interledger-usd-payment'), $ids);
    }

    /**
     * A merchant who removes the Interledger package keeps its stored, active
     * payment methods, and the chain list no longer maps their ids. Such a
     * method must not be offered: quoting it would fail after the customer has
     * chosen it. Another plugin's method still passes.
     */
    public function testAStoredMethodOfAChainThisInstallationNoLongerKnowsIsNotOffered(): void
    {
        $gone = md5('removed-chain-token-payment');
        $this->assertNull(PaymentMethodInstaller::chainAndAssetFor($gone), 'the chain list does not map it');
        $collection = new PaymentMethodCollection([
            $this->method(PaymentMethodInstaller::XRP_PAYMENT_ID, XrplPaymentHandler::class),
            $this->method($gone, XrplPaymentHandler::class),
            $this->method(self::OTHER_METHOD, 'Another\\Plugin\\Handler'),
        ]);

        $ids = $this->loadCollection($collection, Fixtures::getStaticConfiguration())->getIds();

        $this->assertNotContains($gone, $ids, 'its chain is not in the chain list any more');
        $this->assertContains(PaymentMethodInstaller::XRP_PAYMENT_ID, $ids);
        $this->assertContains(self::OTHER_METHOD, $ids, "another plugin's method is untouched");
    }

    /** @param list<array<string, mixed>> $balances */
    private static function account(array $balances): Response
    {
        return new Response(200, ['Content-Type' => 'application/hal+json'], json_encode(['id' => Fixtures::STELLAR_ACCOUNT, 'account_id' => Fixtures::STELLAR_ACCOUNT, 'balances' => $balances, 'flags' => []], JSON_THROW_ON_ERROR));
    }

    private function load(array $configuration): PaymentMethodCollection
    {
        return $this->loadCollection(new PaymentMethodCollection([
            $this->method(PaymentMethodInstaller::XRP_PAYMENT_ID),
            $this->method(PaymentMethodInstaller::RLUSD_PAYMENT_ID),
            $this->method(PaymentMethodInstaller::USDC_PAYMENT_ID),
            $this->method(PaymentMethodInstaller::paymentMethodIdFor('stellar-xlm-payment')),
            $this->method(PaymentMethodInstaller::paymentMethodIdFor('stellar-usdc-payment')),
            $this->method(PaymentMethodInstaller::paymentMethodIdFor('interledger-eur-payment')),
            $this->method(PaymentMethodInstaller::paymentMethodIdFor('interledger-usd-payment')),
            $this->method(self::OTHER_METHOD),
        ]), $configuration);
    }

    private function loadCollection(PaymentMethodCollection $collection, array $configuration): PaymentMethodCollection
    {
        $response = new PaymentMethodRouteResponse(new EntitySearchResult('payment_method', $collection->count(), $collection, null, new Criteria(), Context::createDefaultContext()));

        /** @var AbstractPaymentMethodRoute&MockInterface $inner */
        $inner = Mockery::mock(AbstractPaymentMethodRoute::class);
        $inner->shouldReceive('load')->once()->andReturn($response);
        /** @var SalesChannelContext&MockInterface $salesChannelContext */
        $salesChannelContext = Mockery::mock(SalesChannelContext::class);
        $currency = new CurrencyEntity();
        $currency->setIsoCode($this->currency);
        $salesChannelContext->shouldReceive('getCurrency')->andReturn($currency);

        // The real inspector over the core's fake HTTP client: AccountInspector is final. Without a
        // Stellar method to check, nothing is queued and nothing is asked.
        $inspector = new AccountInspector(new HorizonClient($this->horizon, new HttpFactory()), new NullLogger());
        $configurationService = ConfigurationServiceMock::createInstance($configuration);
        $configProvider = new ShopwareConfigProvider($configurationService);

        $gateway = new InterledgerGateway(
            $configurationService,
            $configProvider,
            new FakeHttpClient(),
            new HttpFactory(),
            Mockery::mock(Connection::class),
            new InMemoryCache(),
            new NullLogger(),
        );

        if ($this->wallet !== null) {
            $gateway->useWallet($this->wallet);
        }

        $decorator = new PaymentMethodRouteDecorator($inner, $configProvider, $inspector, $gateway, new NullLogger());
        $filtered = $decorator->load(new Request(), $salesChannelContext, new Criteria());

        // The Store API serialises the search result, the storefront reads the collection: same answer.
        $this->assertSame(array_values($filtered->getPaymentMethods()->getIds()), array_values($filtered->getObject()->getIds()));
        $this->assertSame($filtered->getPaymentMethods()->count(), $filtered->getObject()->getTotal());

        return $filtered->getPaymentMethods();
    }

    private function method(string $id, ?string $handlerIdentifier = null): PaymentMethodEntity
    {
        $method = new PaymentMethodEntity();
        $method->setId($id);
        $method->setUniqueIdentifier($id);

        if ($handlerIdentifier !== null) {
            $method->setHandlerIdentifier($handlerIdentifier);
        }

        return $method;
    }
}
