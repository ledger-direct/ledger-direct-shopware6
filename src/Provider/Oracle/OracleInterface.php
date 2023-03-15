<?php

namespace LedgerDirect\Provider\Oracle;

interface OracleInterface
{
    public function getCurrentPriceForPair(string $code1, string $code2): float;
}