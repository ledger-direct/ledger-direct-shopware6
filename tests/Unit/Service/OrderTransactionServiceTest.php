<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\OrderTransactionServiceMock;
use Mockery;
use Mockery\Mock;
use PHPUnit\Framework\TestCase;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use \Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\Currency\CurrencyEntity;

class OrderTransactionServiceTest extends TestCase
{
    public function testGetCurrentXrpPriceForOrder(): void
    {
        $orderTransactionService = OrderTransactionServiceMock::createInstance();

        $order = Mockery::mock(OrderEntity::class);
        $order->shouldReceive('getCurrencyId')
            ->andReturn(Uuid::randomHex());
        $order->shouldReceive('getAmountTotal')
            ->andReturn(100.0);

        $context = new Context(
            new SystemSource(),
            versionId: 'random-string',
        );

        $result = $orderTransactionService->getCurrentXrpPriceForOrder($order, $context);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('pairing', $result);
        $this->assertArrayHasKey('exchange_rate', $result);
        $this->assertArrayHasKey('amount_requested', $result);
    }
}