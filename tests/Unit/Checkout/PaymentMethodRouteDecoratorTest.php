<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Checkout;

use Hardcastle\LedgerDirect\Checkout\PaymentMethodRouteDecorator;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
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

    protected function setUp(): void
    {
        $this->horizon = new FakeHttpClient();
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
     * account record. When Horizon cannot be asked, the method stays offered.
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
        $this->assertContains($usdc, $this->load($configuration)->getIds(), 'Horizon down: offered, logged');
    }

    /** @param list<array<string, mixed>> $balances */
    private static function account(array $balances): Response
    {
        return new Response(200, ['Content-Type' => 'application/hal+json'], json_encode(['id' => Fixtures::STELLAR_ACCOUNT, 'account_id' => Fixtures::STELLAR_ACCOUNT, 'balances' => $balances, 'flags' => []], JSON_THROW_ON_ERROR));
    }

    private function load(array $configuration): PaymentMethodCollection
    {
        $collection = new PaymentMethodCollection([
            $this->method(PaymentMethodInstaller::XRP_PAYMENT_ID),
            $this->method(PaymentMethodInstaller::RLUSD_PAYMENT_ID),
            $this->method(PaymentMethodInstaller::USDC_PAYMENT_ID),
            $this->method(PaymentMethodInstaller::paymentMethodIdFor('stellar-xlm-payment')),
            $this->method(PaymentMethodInstaller::paymentMethodIdFor('stellar-usdc-payment')),
            $this->method(self::OTHER_METHOD),
        ]);
        $response = new PaymentMethodRouteResponse(new EntitySearchResult('payment_method', 6, $collection, null, new Criteria(), Context::createDefaultContext()));

        /** @var AbstractPaymentMethodRoute&MockInterface $inner */
        $inner = Mockery::mock(AbstractPaymentMethodRoute::class);
        $inner->shouldReceive('load')->once()->andReturn($response);
        /** @var SalesChannelContext&MockInterface $salesChannelContext */
        $salesChannelContext = Mockery::mock(SalesChannelContext::class);

        // The real inspector over the core's fake HTTP client: AccountInspector is final. Without a
        // Stellar method to check, nothing is queued and nothing is asked.
        $inspector = new AccountInspector(new HorizonClient($this->horizon, new HttpFactory()), new NullLogger());
        $decorator = new PaymentMethodRouteDecorator($inner, new ShopwareConfigProvider(ConfigurationServiceMock::createInstance($configuration)), $inspector, new NullLogger());
        $filtered = $decorator->load(new Request(), $salesChannelContext, new Criteria());

        // The Store API serialises the search result, the storefront reads the collection: same answer.
        $this->assertSame(array_values($filtered->getPaymentMethods()->getIds()), array_values($filtered->getObject()->getIds()));
        $this->assertSame($filtered->getPaymentMethods()->count(), $filtered->getObject()->getTotal());

        return $filtered->getPaymentMethods();
    }

    private function method(string $id): PaymentMethodEntity
    {
        $method = new PaymentMethodEntity();
        $method->setId($id);
        $method->setUniqueIdentifier($id);

        return $method;
    }
}
