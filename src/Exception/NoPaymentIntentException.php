<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The order exists and may be seen, but was never prepared for an XRPL
 * payment — the payment page renders an error and no poll for such an
 * order, so this only answers a hand-made request.
 */
class NoPaymentIntentException extends ShopwareHttpException
{
    public function __construct(string $orderId)
    {
        parent::__construct('Order {{ orderId }} has no XRPL payment attached.', ['orderId' => $orderId]);
    }

    public function getErrorCode(): string
    {
        return 'LEDGER_DIRECT__NO_PAYMENT_INTENT';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
