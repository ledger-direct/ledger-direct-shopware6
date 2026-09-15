<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Key knowledge, not account membership: the deepLinkCode opens the order
 * for anyone who has it, the session customer opens their own order without
 * it, and nobody else learns whether the order exists.
 */
class OrderAccessGuardTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ORDER_ID = 'order-id';

    private const DEEP_LINK_CODE = 'aBcDeFgHiJkLmNoPqRsTuVwXyZ012345';

    private const CUSTOMER_ID = 'customer-id';

    private OrderTransactionService $orderTransactionService;

    private Context $context;

    protected function setUp(): void
    {
        $this->orderTransactionService = Mockery::mock(OrderTransactionService::class);
        $this->context = new Context(new SystemSource());
    }

    public function testTheDeepLinkCodeOpensTheOrderWithoutASession(): void
    {
        $this->givenOrder();

        $order = $this->guard()->authorisedOrder(
            self::ORDER_ID,
            new Request(['deepLinkCode' => self::DEEP_LINK_CODE]),
            $this->salesChannelContext(customer: null)
        );

        $this->assertNotNull($order);
        $this->assertSame(self::ORDER_ID, $order->getId());
    }

    public function testTheOwningCustomerOpensTheOrderWithoutACode(): void
    {
        $this->givenOrder();

        $order = $this->guard()->authorisedOrder(
            self::ORDER_ID,
            new Request(),
            $this->salesChannelContext(customer: self::CUSTOMER_ID)
        );

        $this->assertNotNull($order);
    }

    public function testAWrongCodeIsRefusedEvenForAnotherLoggedInCustomer(): void
    {
        $this->givenOrder();

        $order = $this->guard()->authorisedOrder(
            self::ORDER_ID,
            new Request(['deepLinkCode' => 'not-the-code']),
            $this->salesChannelContext(customer: 'somebody-else')
        );

        $this->assertNull($order);
    }

    public function testNoCodeAndNoSessionIsRefused(): void
    {
        $this->givenOrder();

        $this->assertNull($this->guard()->authorisedOrder(
            self::ORDER_ID,
            new Request(),
            $this->salesChannelContext(customer: null)
        ));
    }

    /**
     * An order without a code must not be opened by an empty one.
     */
    public function testAnEmptyCodeNeverMatchesAnOrderWithoutOne(): void
    {
        $this->givenOrder(deepLinkCode: '');

        $this->assertNull($this->guard()->authorisedOrder(
            self::ORDER_ID,
            new Request(['deepLinkCode' => '']),
            $this->salesChannelContext(customer: null)
        ));
    }

    public function testAMissingOrderIsRefusedTheSameWay(): void
    {
        $this->orderTransactionService->shouldReceive('getOrderWithTransactions')
            ->with(self::ORDER_ID, $this->context)
            ->andReturn(null);

        $this->assertNull($this->guard()->authorisedOrder(
            self::ORDER_ID,
            new Request(['deepLinkCode' => self::DEEP_LINK_CODE]),
            $this->salesChannelContext(customer: self::CUSTOMER_ID)
        ));
    }

    private function guard(): OrderAccessGuard
    {
        return new OrderAccessGuard($this->orderTransactionService);
    }

    private function givenOrder(string $deepLinkCode = self::DEEP_LINK_CODE): void
    {
        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setCustomerId(self::CUSTOMER_ID);

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setDeepLinkCode($deepLinkCode);
        $order->setOrderCustomer($orderCustomer);

        $this->orderTransactionService->shouldReceive('getOrderWithTransactions')
            ->with(self::ORDER_ID, $this->context)
            ->andReturn($order);
    }

    private function salesChannelContext(?string $customer): SalesChannelContext
    {
        $customerEntity = null;

        if ($customer !== null) {
            $customerEntity = new CustomerEntity();
            $customerEntity->setId($customer);
        }

        $salesChannelContext = Mockery::mock(SalesChannelContext::class);
        $salesChannelContext->shouldReceive('getContext')->andReturn($this->context);
        $salesChannelContext->shouldReceive('getCustomer')->andReturn($customerEntity);

        return $salesChannelContext;
    }
}
