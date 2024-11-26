<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Fixtures;

class Fixtures
{
    public static function getCurrentConfiguration(): array
    {
        return [
            'LedgerDirect.config.useTestnet' => true,
            'LedgerDirect.config.xrplTestnetAccount' => getenv('LEDGER_DIRECT_TEST_XRPL_ADDRESS'),
            'LedgerDirect.config.xrplTestnetCustomTokenName' => 'EUR',
            'LedgerDirect.config.xrplTestnetCustomTokenIssuer' => 'rUFqxm6cfRQTvxgAqJny1dGMprrXQXhTLb',
        ];
    }

    public static function getStaticConfiguration(): array
    {
        return [
            'LedgerDirect.config.useTestnet' => true,
            'LedgerDirect.config.xrplTestnetAccount' => 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr',
            'LedgerDirect.config.xrplTestnetCustomTokenName' => 'EUR',
            'LedgerDirect.config.xrplTestnetCustomTokenIssuer' => 'rUFqxm6cfRQTvxgAqJny1dGMprrXQXhTLb',
        ];
    }

    public static function getStaticXrplClientConfiguration(): array
    {
        return [
            'network' => 'testnet',
            'server' => 'wss://s.altnet.rippletest.net:51233',
            'address' => 'rHb9CJAWyB4rj91VRWn96DkukG4bwdtyTh',
            'secret' => 'snoPBrXtMeMyMHUVTgbuqAfg1SUTb',
        ];
    }
}