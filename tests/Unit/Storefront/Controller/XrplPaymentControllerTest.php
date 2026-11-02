<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Storefront\Controller;

use Hardcastle\LedgerDirect\Exception\NoPaymentIntentException;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Storefront\Controller\XrplPaymentController;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The payment page's poll refuses with JSON, whatever the environment —
 * the same bodies Magento's and PrestaShop's status endpoints send.
 */
class XrplPaymentControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testAForbiddenPollAnswersWithJson(): void
    {
        $response = $this->checkPaymentThrowing(CartException::insufficientPermission());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'forbidden'], json_decode((string) $response->getContent(), true));
    }

    public function testAnOrderWithoutPaymentAnswersWithJson(): void
    {
        $response = $this->checkPaymentThrowing(new NoPaymentIntentException('order-id'));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'no_payment_intent'], json_decode((string) $response->getContent(), true));
    }

    public function testOtherCartErrorsAreNotSwallowed(): void
    {
        $other = CartException::orderNotFound('order-id');
        $this->assertNotSame(403, $other->getStatusCode());

        $this->expectExceptionObject($other);
        $this->checkPaymentThrowing($other);
    }

    private function checkPaymentThrowing(\Throwable $exception): \Symfony\Component\HttpFoundation\Response
    {
        $route = Mockery::mock(PaymentRoute::class);
        $route->shouldReceive('check')->once()->andThrow($exception);

        $controller = (new \ReflectionClass(XrplPaymentController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(XrplPaymentController::class, 'paymentRoute'))->setValue($controller, $route);

        return $controller->checkPayment(Mockery::mock(SalesChannelContext::class), 'order-id', new Request());
    }
}
