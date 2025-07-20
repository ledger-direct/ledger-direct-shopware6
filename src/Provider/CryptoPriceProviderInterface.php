<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Provider;

interface CryptoPriceProviderInterface
{
    public function getCurrentExchangeRate(string $code, ?bool $round = false): float|false;

    public function checkPricePlausibility(float $price): bool;
}