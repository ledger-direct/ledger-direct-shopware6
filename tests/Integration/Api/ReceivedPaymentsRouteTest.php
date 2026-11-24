<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Api;

use Hardcastle\LedgerDirect\Api\ReceivedPaymentsController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The route in the booted shop: registered under the API scope with the
 * privilege Shopware holds it to, and its controller comes out of the
 * container with what it needs. The rows are the unit tests' subject.
 *
 * No request with a login here: the suite's APP_SECRET is too short for the
 * token signer, so the administration's API cannot be logged into from it.
 */
class ReceivedPaymentsRouteTest extends TestCase
{
    use KernelTestBehaviour;

    public function testTheShopKnowsTheRouteAndItsPrivilege(): void
    {
        $route = $this->getContainer()->get('router')->getRouteCollection()->get('api.action.ledger-direct.order.payments');

        $this->assertNotNull($route, 'the route is not registered');
        $this->assertSame('/api/_action/ledger-direct/order/{orderId}/payments', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertSame(['order:read'], $route->getDefault('_acl'));
        $this->assertSame(['api'], $route->getDefault('_routeScope'));
    }

    public function testTheControllerIsWiredAndAsksTheShopsOrders(): void
    {
        $controller = $this->getContainer()->get(ReceivedPaymentsController::class);
        $this->assertInstanceOf(ReceivedPaymentsController::class, $controller);

        $response = $controller->payments(Uuid::randomHex(), Context::createDefaultContext());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['error' => 'order_not_found'], json_decode((string) $response->getContent(), true));
    }
}
