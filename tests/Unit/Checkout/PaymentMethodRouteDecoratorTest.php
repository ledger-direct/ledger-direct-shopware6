<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Checkout;

use Hardcastle\LedgerDirect\Checkout\PaymentMethodRouteDecorator;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Tests\Fixtures\Fixtures;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
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

    private function load(array $configuration): PaymentMethodCollection
    {
        $collection = new PaymentMethodCollection([
            $this->method(PaymentMethodInstaller::XRP_PAYMENT_ID),
            $this->method(PaymentMethodInstaller::RLUSD_PAYMENT_ID),
            $this->method(PaymentMethodInstaller::USDC_PAYMENT_ID),
            $this->method(self::OTHER_METHOD),
        ]);
        $response = new PaymentMethodRouteResponse(new EntitySearchResult('payment_method', 4, $collection, null, new Criteria(), Mockery::mock(\Shopware\Core\Framework\Context::class)));

        $inner = Mockery::mock(AbstractPaymentMethodRoute::class);
        $inner->shouldReceive('load')->once()->andReturn($response);

        $decorator = new PaymentMethodRouteDecorator($inner, new ShopwareConfigProvider(ConfigurationServiceMock::createInstance($configuration)));

        return $decorator->load(new Request(), Mockery::mock(SalesChannelContext::class), new Criteria())->getPaymentMethods();
    }

    private function method(string $id): PaymentMethodEntity
    {
        $method = new PaymentMethodEntity();
        $method->setId($id);
        $method->setUniqueIdentifier($id);

        return $method;
    }
}
