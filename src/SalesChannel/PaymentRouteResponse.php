<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\SalesChannel;

use Shopware\Core\System\SalesChannel\StoreApiResponse;

class PaymentRouteResponse extends StoreApiResponse
{
    public function getResult(): array
    {
        return [];
    }
}