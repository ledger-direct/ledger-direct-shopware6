<?php declare(strict_types=1);

namespace LedgerDirect\Exception;

use Shopware\Core\Framework\ShopwareHttpException;

class TransactionLifetimeException extends ShopwareHttpException
{

    public function getErrorCode(): string
    {
        return 'LEDGER_DIRECT__TRANSACTION_TIMED_OUT';
    }
}
