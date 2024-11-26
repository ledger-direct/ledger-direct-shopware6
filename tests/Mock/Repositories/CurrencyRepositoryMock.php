<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Mock\Repositories;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

class CurrencyRepositoryMock
{
    public function search(Criteria $criteria, Context $context): EntitySearchResult
    {
        return new EntitySearchResult('currency', 0, [], null, $criteria, $context);
    }
}