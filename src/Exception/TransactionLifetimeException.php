<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The quote asked for has expired: its exchange rate is stale and the amount
 * is not handed out again as if it were current. Gone, not a server error —
 * a headless front end acts on the status code, and 500 would read as a
 * fault of the shop.
 */
class TransactionLifetimeException extends ShopwareHttpException
{
    public function getStatusCode(): int
    {
        return Response::HTTP_GONE;
    }

    public function getErrorCode(): string
    {
        return 'LEDGER_DIRECT__TRANSACTION_TIMED_OUT';
    }
}
