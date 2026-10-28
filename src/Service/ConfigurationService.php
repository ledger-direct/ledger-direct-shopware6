<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    private const CONFIG_DOMAIN = 'LedgerDirect';

    /**
     * The quote validity is global — one value for every chain — but keeps
     * the key it has always had, so no installation has to migrate it.
     */
    private const CONFIG_KEY_QUOTE_EXPIRY = 'xrplQuoteExpiry';

    // Payment page card — keys as in config.xml, checked there, not against the code.
    private const CONFIG_KEY_LOGO_MODE = 'paymentPageLogoMode';

    private const CONFIG_KEY_LOGO_MEDIA_ID = 'paymentPageLogo';

    private const CONFIG_KEY_ACCENT_COLOR = 'paymentPageAccentColor';

    private const CONFIG_KEY_XAMAN_API_KEY = 'xamanApiKey';

    private const CONFIG_KEY_WALLETCONNECT_PROJECT_ID = 'walletConnectProjectId';

    public const LOGO_MODE_SHOP = 'shop';

    public const LOGO_MODE_CUSTOM = 'custom';

    public const LOGO_MODE_NONE = 'none';

    public const DEFAULT_QUOTE_EXPIRY_SECONDS = 300;

    private SystemConfigService $systemConfigService;

    private LoggerInterface $logger;

    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * Reads a LedgerDirect plugin configuration value.
     *
     * @param string $configName Config key without the "LedgerDirect.config." prefix.
     * @param mixed $defaultValue Returned when the stored value is empty.
     * @return mixed The stored value, or the default when empty.
     */
    public function get(string $configName, mixed $defaultValue = null): mixed
    {
        $value = $this->systemConfigService->get(self::CONFIG_DOMAIN . '.config.' . $configName);
        if (empty($value)) {
            if (!is_null($defaultValue)) {
                return $defaultValue;
            }
            $this->logger->error('Configuration value not found: ' . $configName);
        }

        return $value;
    }

    /**
     * Reads a boolean setting.
     *
     * Deliberately not routed through get(): that treats an empty value as
     * "unset" and hands back the default, which for a bool would make a
     * merchant's explicit "off" indistinguishable from "never configured" —
     * the toggle could then never be switched off. Only null means unset here.
     */
    public function getBool(string $configName, bool $defaultValue): bool
    {
        $value = $this->systemConfigService->get(self::CONFIG_DOMAIN . '.config.' . $configName);

        return $value === null ? $defaultValue : (bool) $value;
    }

    /**
     * Reads an integer setting, falling back to the default when unset or
     * not a usable number.
     */
    public function getInt(string $configName, int $defaultValue): int
    {
        $value = $this->systemConfigService->get(self::CONFIG_DOMAIN . '.config.' . $configName);

        return is_numeric($value) ? (int) $value : $defaultValue;
    }

    /*
     * Per-chain settings. One card per chain in config.xml, its keys named
     * after the chain in lower case, the way the XRPL keys always were:
     * `xrplEnabled`, `useXrplTestnet`, `xrplMainnetDestinationAccount`,
     * `xrplTestnetDestinationAccount`, `xrplIsRlusdEnabled`. A second chain
     * follows the same pattern (`stellarEnabled`, `useStellarTestnet`, …),
     * so nothing here knows a chain by name. The chain names themselves come
     * from the core's ChainCatalog; see ShopwareConfigProvider.
     */

    /**
     * Whether the merchant accepts payments on this chain at all. The XRPL
     * switch did not exist before 1.6.0: an installation that has a receiving
     * account configured keeps working as before, one without has nothing
     * to switch on. Any other chain is off until the merchant turns it on.
     */
    public function isChainEnabled(string $chain): bool
    {
        $stored = $this->systemConfigService->get(self::CONFIG_DOMAIN . '.config.' . $this->chainKey($chain, 'Enabled'));

        if ($stored !== null) {
            return (bool) $stored;
        }

        return $this->getAccount($chain, $this->getNetwork($chain)) !== '';
    }

    /**
     * Whether this chain runs against its testnet (true) or mainnet (false).
     * Testnet by default, as the XRPL toggle always was: a fresh installation
     * must not quote real money before the merchant has looked at it.
     */
    public function isTestnet(string $chain): bool
    {
        return $this->getBool('use' . ucfirst(strtolower($chain)) . 'Testnet', true);
    }

    /**
     * The active network of this chain: "testnet" or "mainnet".
     */
    public function getNetwork(string $chain): string
    {
        return $this->isTestnet($chain) ? 'testnet' : 'mainnet';
    }

    /**
     * The merchant's receiving account on this chain for its active network;
     * '' when none is configured.
     */
    public function getDestinationAccount(string $chain): string
    {
        return $this->getAccount($chain, $this->getNetwork($chain));
    }

    /**
     * The receiving account configured for one network of a chain, '' when
     * none. Trimmed: an account is copied from a wallet, and a stray space or
     * line break around it is not part of it.
     */
    public function getAccount(string $chain, string $network): string
    {
        return $this->getString($this->chainKey($chain, ucfirst($network) . 'DestinationAccount'));
    }

    /**
     * The per-asset toggle (`xrplIsRlusdEnabled`), on by default as the XRPL
     * toggles always were. Whether the asset exists on the chain, or is the
     * native one that has no toggle, is the config provider's business.
     */
    public function isAssetEnabled(string $chain, string $assetCode): bool
    {
        return $this->getBool($this->chainKey($chain, 'Is' . ucfirst(strtolower($assetCode)) . 'Enabled'), true);
    }

    private function chainKey(string $chain, string $suffix): string
    {
        return strtolower($chain) . $suffix;
    }

    /**
     * How long a price quote handed to the customer stays valid, in seconds.
     */
    public function getQuoteExpirySeconds(): int
    {
        return $this->getInt(self::CONFIG_KEY_QUOTE_EXPIRY, self::DEFAULT_QUOTE_EXPIRY_SECONDS);
    }

    /**
     * Reads a string setting; an unset or non-string value is the default.
     */
    public function getString(string $configName, string $defaultValue = ''): string
    {
        return $this->getStringFor($configName, null, $defaultValue);
    }

    /**
     * Reads a string setting as it applies to one sales channel: the value
     * saved for that channel, or the global one where the channel has none —
     * Shopware's own inheritance. Null reads the global value alone.
     */
    public function getStringFor(string $configName, ?string $salesChannelId, string $defaultValue = ''): string
    {
        $value = $this->systemConfigService->get(self::CONFIG_DOMAIN . '.config.' . $configName, $salesChannelId);

        return is_string($value) ? trim($value) : $defaultValue;
    }

    /** One of the LOGO_MODE_* constants; anything else reads as the default, the shop logo. */
    public function getPaymentPageLogoMode(): string
    {
        $mode = $this->getString(self::CONFIG_KEY_LOGO_MODE, self::LOGO_MODE_SHOP);

        return in_array($mode, [self::LOGO_MODE_SHOP, self::LOGO_MODE_CUSTOM, self::LOGO_MODE_NONE], true)
            ? $mode
            : self::LOGO_MODE_SHOP;
    }

    /** The media id chosen for the payment page logo, or null. */
    public function getPaymentPageLogoMediaId(): ?string
    {
        $id = $this->getString(self::CONFIG_KEY_LOGO_MEDIA_ID);

        return $id === '' ? null : $id;
    }

    /** The accent colour as stored; validation and the contrast rule live in the core's Presentation\AccentColor. */
    public function getPaymentPageAccentColor(): string
    {
        return $this->getString(self::CONFIG_KEY_ACCENT_COLOR);
    }

    public function getXamanApiKey(): string
    {
        return $this->getString(self::CONFIG_KEY_XAMAN_API_KEY);
    }

    public function getWalletConnectProjectId(): string
    {
        return $this->getString(self::CONFIG_KEY_WALLETCONNECT_PROJECT_ID);
    }
}
