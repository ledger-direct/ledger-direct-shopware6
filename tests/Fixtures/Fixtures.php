<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Fixtures;

class Fixtures
{
    /** A testnet Stellar account (the core's recorded merchant account). */
    public const STELLAR_ACCOUNT = 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA';

    /** A testnet Xahau account (the core's recorded merchant account). An `r…` address, as on the XRP Ledger. */
    public const XAHAU_ACCOUNT = 'r4ERx8577eKBSAT1pLefizNPYCYfhk1op2';

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

    /** The static configuration plus a switched-on Stellar card on the testnet. */
    public static function getStaticMultiChainConfiguration(): array
    {
        return self::getStaticConfiguration() + [
            'LedgerDirect.config.stellarEnabled' => true,
            'LedgerDirect.config.useStellarTestnet' => true,
            'LedgerDirect.config.stellarTestnetDestinationAccount' => self::STELLAR_ACCOUNT,
            'LedgerDirect.config.stellarIsUsdcEnabled' => true,
            'LedgerDirect.config.stellarIsEurcEnabled' => false,
        ];
    }

    /** The static configuration plus a switched-on Xahau card on the testnet. */
    public static function getStaticXahauConfiguration(): array
    {
        return self::getStaticConfiguration() + [
            'LedgerDirect.config.xahauEnabled' => true,
            'LedgerDirect.config.useXahauTestnet' => true,
            'LedgerDirect.config.xahauTestnetDestinationAccount' => self::XAHAU_ACCOUNT,
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
