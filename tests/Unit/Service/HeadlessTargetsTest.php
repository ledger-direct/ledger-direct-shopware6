<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\HeadlessTargets;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The external front end's targets: from the merchant's templates, per sales
 * channel, URL-encoded; null — the storefront's pages — where none is set.
 */
class HeadlessTargetsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testNothingConfiguredMeansTheStorefront(): void
    {
        $targets = new HeadlessTargets(ConfigurationServiceMock::createInstance([]));

        $this->assertNull($targets->paymentPageUrl($this->order(), 'https://shop.example/return'));
        $this->assertNull($targets->orderPageUrl($this->order()));
    }

    public function testThePlaceholdersAreFilledInUrlEncoded(): void
    {
        $targets = new HeadlessTargets(ConfigurationServiceMock::createInstance([
            'LedgerDirect.config.headlessPaymentPageUrl' => 'https://frontend.example/de/pay/{orderId}?code={deepLinkCode}&return={returnUrl}',
            'LedgerDirect.config.headlessOrderPageUrl' => 'https://frontend.example/de/orders/{orderId}?code={deepLinkCode}',
        ]));

        $this->assertSame(
            'https://frontend.example/de/pay/order-id?code=a-b_C&return=https%3A%2F%2Fshop.example%2Freturn%3F_sw_payment_token%3Dt%26x%3D1',
            $targets->paymentPageUrl($this->order(), 'https://shop.example/return?_sw_payment_token=t&x=1')
        );
        $this->assertSame('https://frontend.example/de/orders/order-id?code=a-b_C', $targets->orderPageUrl($this->order()));
    }

    /** The template is read for the order's sales channel: the one with a front end gets it, the storefront channel does not. */
    public function testTheTemplateIsReadForTheOrdersSalesChannel(): void
    {
        /** @var SystemConfigService&Mockery\MockInterface $systemConfig */
        $systemConfig = Mockery::mock(SystemConfigService::class);
        $systemConfig->shouldReceive('get')->with('LedgerDirect.config.headlessPaymentPageUrl', 'headless-channel')->andReturn('https://frontend.example/pay/{orderId}?code={deepLinkCode}');
        $systemConfig->shouldReceive('get')->with('LedgerDirect.config.headlessPaymentPageUrl', 'storefront-channel')->andReturn(null);
        $targets = new HeadlessTargets(new ConfigurationService($systemConfig, Mockery::mock(LoggerInterface::class)));

        $this->assertSame('https://frontend.example/pay/order-id?code=a-b_C', $targets->paymentPageUrl($this->order('headless-channel'), ''));
        $this->assertNull($targets->paymentPageUrl($this->order('storefront-channel'), ''));
    }

    public function testProblemNamesWhatIsWrongWithATemplate(): void
    {
        $allowed = HeadlessTargets::PAYMENT_PAGE_PLACEHOLDERS;
        $required = HeadlessTargets::PAYMENT_PAGE_REQUIRED;

        $this->assertNull(HeadlessTargets::problem('https://f.example/pay/{orderId}?c={deepLinkCode}', $allowed, $required));
        $this->assertNull(HeadlessTargets::problem('http://localhost:3000/pay/{orderId}/{deepLinkCode}/{returnUrl}', $allowed, $required), 'http for a development front end');
        $this->assertSame('must contain {orderId}', HeadlessTargets::problem('https://f.example/pay?c={deepLinkCode}', $allowed, $required));
        $this->assertSame('must contain {deepLinkCode}', HeadlessTargets::problem('https://f.example/pay/{orderId}', $allowed, $required));
        $this->assertStringStartsWith('must be an absolute URL', (string) HeadlessTargets::problem('/pay/{orderId}?c={deepLinkCode}', $allowed, $required));
        $this->assertStringStartsWith('must be an absolute URL', (string) HeadlessTargets::problem('javascript:alert({orderId}{deepLinkCode})', $allowed, $required));
        $this->assertStringStartsWith('names an unknown placeholder {secret}', (string) HeadlessTargets::problem('https://f.example/{orderId}/{deepLinkCode}/{secret}', $allowed, $required));
    }

    private function order(string $salesChannelId = 'sales-channel-id'): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setDeepLinkCode('a-b_C');
        $order->setSalesChannelId($salesChannelId);

        return $order;
    }
}
