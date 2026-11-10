<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * No price for a new quote right now: the core's PriceService found no
 * usable rate, or the oracles disagree (since core 0.11.1 no stale rate
 * stands in for those). Nothing was stored — an existing quote stays as it
 * was — and asking again a little later may well succeed.
 *
 * 409, not a server error: a front end acts on the status code, and the
 * error code tells it to offer "try again". An HttpException, not a plain
 * ShopwareHttpException, so Shopware's payment processor hands the error
 * code on to the error URL of a failed checkout payment as well.
 */
class PriceTemporarilyUnavailableException extends HttpException
{
    public const ERROR_CODE = 'LEDGER_DIRECT__PRICE_UNAVAILABLE';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            Response::HTTP_CONFLICT,
            self::ERROR_CODE,
            'The price is temporarily unavailable. Please try again in a moment.',
            [],
            $previous
        );
    }
}
