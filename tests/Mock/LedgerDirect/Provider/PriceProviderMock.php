<?php declare(strict_types=1);

namespace Mock\LedgerDirect\Provider;

use GuzzleHttp\Client;
use Hardcastle\LedgerDirect\Provider\CryptoPriceProviderInterface;
use Hardcastle\LedgerDirect\Provider\XrpPriceProvider;
use Mockery;

class PriceProviderMock
{
    public function setUp()
    {

    }
    public static function createInstance(): CryptoPriceProviderInterface
    {
        $client = new Client();

        return new XrpPriceProvider($client);
    }

    public static function createMock(): CryptoPriceProviderInterface
    {
        return Mockery::mock(CryptoPriceProviderInterface::class);
    }
}