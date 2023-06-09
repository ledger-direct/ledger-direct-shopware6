<?php declare(strict_types=1);

namespace LedgerDirect\Provider;

interface CryptoPriceProviderInterface
{
    public function getCurrentExchangeRate(string $code): float;

    public function checkPricePlausibility(float $price): bool;
}