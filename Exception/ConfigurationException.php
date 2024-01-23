<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Exception;

use Exception;

class ConfigurationException extends Exception
{
    public function __construct(string $message = "", int $code = 0, Exception $previous = null)
    {
        $message = "[LedgerDirect] configuration error: " . $message;
        parent::__construct($message, $code, $previous);
    }
}