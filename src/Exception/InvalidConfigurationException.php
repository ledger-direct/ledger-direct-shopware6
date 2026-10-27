<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A plugin setting the merchant tried to save is not acceptable; the save
 * is refused with this message. 400, so the administration shows the text
 * rather than a generic server error.
 */
class InvalidConfigurationException extends ShopwareHttpException
{
    public function __construct(string $message, array $parameters = [])
    {
        parent::__construct($message, $parameters);
    }

    public function getErrorCode(): string
    {
        return 'LEDGER_DIRECT__INVALID_CONFIGURATION';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
