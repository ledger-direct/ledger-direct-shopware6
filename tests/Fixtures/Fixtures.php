<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Fixtures;

class Fixtures
{
    /** A testnet Stellar account (the core's recorded merchant account). */
    public const STELLAR_ACCOUNT = 'GCGEFYLIJXTISHBB3LENEJNGDBU7XHEI4F2PTLY4OSZ5Q5YF7ZWVNQIA';

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
    public const INTERLEDGER_WALLET = 'https://ilp.example.test/merchant';

    /** base64 of a PEM that is not a key: enough for Credentials, useless for signing. */
    public const INTERLEDGER_PRIVATE_KEY = 'LS0tLS1CRUdJTiBQUklWQVRFIEtFWS0tLS0tClRrOVVRVkpGUVV4TFJVWT0KLS0tLS1FTkQgUFJJVkFURSBLRVktLS0tLQo=';

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

    /**
     * The Interledger card filled in: wallet address, key id and a key in
     * the shape the SDK signs with. The key is a throwaway PEM — nothing is
     * signed in a unit test, but Credentials refuses anything that is not
     * base64 of a PEM, and that check is part of what is being tested.
     */
    public static function getStaticInterledgerConfiguration(): array
    {
        return self::getStaticConfiguration() + [
            'LedgerDirect.config.interledgerEnabled' => true,
            'LedgerDirect.config.useInterledgerTestnet' => true,
            'LedgerDirect.config.interledgerTestnetDestinationAccount' => self::INTERLEDGER_WALLET,
            'LedgerDirect.config.interledgerKeyId' => '4a1f0d6e-7c3b-4a52-9a2e-1b8c5d0f9e77',
            'LedgerDirect.config.interledgerPrivateKey' => self::INTERLEDGER_PRIVATE_KEY,
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
