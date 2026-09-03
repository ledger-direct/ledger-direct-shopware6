<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Fixtures;

class Fixtures
{
    public static function getIntegrationTestConfiguration(): array
    {
        return [
            'LedgerDirect.config.useXrplTestnet' => true,
            'LedgerDirect.config.xrplTestnetDestinationAccount' => getenv('LEDGER_DIRECT_TEST_XRPL_ADDRESS'),
            'LedgerDirect.config.xrplIsRlusdEnabled' => true,
            'LedgerDirect.config.xrplIsUsdcEnabled' => true,
            'LedgerDirect.config.xrplQuoteExpiry' => 300,
        ];
    }

    public static function getStaticConfiguration(): array
    {
        return [
            'LedgerDirect.config.useXrplTestnet' => true,
            'LedgerDirect.config.xrplTestnetDestinationAccount' => 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr',
            'LedgerDirect.config.xrplMainnetDestinationAccount' => 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De',
            'LedgerDirect.config.xrplIsRlusdEnabled' => true,
            'LedgerDirect.config.xrplIsUsdcEnabled' => false,
            'LedgerDirect.config.xrplQuoteExpiry' => 600,
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
