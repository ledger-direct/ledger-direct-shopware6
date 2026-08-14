<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Service;

use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\OrderTransactionServiceMock;
use Mockery;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

class OrderTransactionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testGetCryptoPriceForOrder(): void
    {
        $orderTransactionService = OrderTransactionServiceMock::createInstance();

        $order = Mockery::mock(OrderEntity::class);
        $order->shouldReceive('getCurrencyId')->andReturn(Uuid::randomHex());
        $order->shouldReceive('getAmountTotal')->andReturn(100.0);

        $context = new Context(new SystemSource(), versionId: 'random-string');

        $result = $orderTransactionService->getCryptoPriceForOrder($order, $context, 'XRP');

        $this->assertIsArray($result);
        // Regression guard for the pairing / asset-metadata fix:
        // the pairing must reflect the real asset, not a hard-coded "XRP".
        $this->assertSame('XRP', $result['base_asset']);
        $this->assertSame('EUR', $result['quote_currency']);
        $this->assertSame('XRP/EUR', $result['pairing']);
        $this->assertSame(2.5, $result['exchange_rate']);
        $this->assertSame(40.0, $result['amount_requested']); // 100.0 / 2.5
    }
}
