<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Where the customer goes once the transaction is closed: Shopware's
 * returnUrl while its token lives, the order page afterwards — and never
 * the returnUrl as handed in.
 */
class PaymentRedirectTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const NOW = 1_700_000_000;

    private RouterInterface $router;

    protected function setUp(): void
    {
        $this->router = Mockery::mock(RouterInterface::class);
    }

    public function testAReturnUrlWithALiveTokenLeadsToTheFinalizeRoute(): void
    {
        $token = self::jwt(['exp' => self::NOW + 1000]);

        $this->router->shouldReceive('generate')
            ->once()
            ->with('payment.finalize.transaction', ['_sw_payment_token' => $token], UrlGeneratorInterface::ABSOLUTE_URL)
            ->andReturn('https://shop.example/payment/finalize-transaction?_sw_payment_token=' . $token);

        $target = $this->redirect()->target($this->order(), 'https://shop.example/payment/finalize-transaction?_sw_payment_token=' . $token, self::NOW);

        $this->assertStringStartsWith('https://shop.example/payment/finalize-transaction', $target);
    }

    /**
     * The token lives 30 minutes. A customer who pays after that must not be
     * sent into Shopware's "token expired" page while their order is paid.
     */
    public function testAnExpiredTokenLeadsToTheOrderPage(): void
    {
        $token = self::jwt(['exp' => self::NOW - 1]);
        $this->expectOrderPage();

        $target = $this->redirect()->target($this->order(), 'https://shop.example/payment/finalize-transaction?_sw_payment_token=' . $token, self::NOW);

        $this->assertSame('https://shop.example/account/order/the-code', $target);
    }

    public function testNoReturnUrlLeadsToTheOrderPage(): void
    {
        $this->expectOrderPage();

        $this->assertSame('https://shop.example/account/order/the-code', $this->redirect()->target($this->order(), null, self::NOW));
    }

    /**
     * The returnUrl comes from the customer's request. A URL without
     * Shopware's token — whatever host it names — is not followed.
     */
    public function testAReturnUrlWithoutATokenIsNotFollowed(): void
    {
        $this->expectOrderPage();

        $this->assertSame(
            'https://shop.example/account/order/the-code',
            $this->redirect()->target($this->order(), 'https://evil.example/phish', self::NOW)
        );
    }

    public function testAMalformedTokenIsNotFollowed(): void
    {
        $this->expectOrderPage();

        $orderPage = 'https://shop.example/account/order/the-code';

        $this->assertSame($orderPage, $this->redirect()->target($this->order(), 'https://shop.example/x?_sw_payment_token=not.a.jwt.at.all', self::NOW));
        $this->assertSame($orderPage, $this->redirect()->target($this->order(), 'https://shop.example/x?_sw_payment_token=' . self::jwt(['sub' => 'no-exp']), self::NOW));
    }

    private function expectOrderPage(): void
    {
        $this->router->shouldReceive('generate')
            ->with('frontend.account.order.single.page', ['deepLinkCode' => 'the-code'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->andReturn('https://shop.example/account/order/the-code');
    }

    private function redirect(): PaymentRedirect
    {
        return new PaymentRedirect($this->router);
    }

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setDeepLinkCode('the-code');

        return $order;
    }

    /**
     * An unsigned JWT with the given claims — the signature is not what the
     * redirect looks at.
     */
    private static function jwt(array $claims): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']) . '.' . $encode($claims) . '.sig';
    }
}
