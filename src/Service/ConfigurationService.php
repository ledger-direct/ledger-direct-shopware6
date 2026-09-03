<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    private const CONFIG_DOMAIN = 'LedgerDirect';

    private const CONFIG_KEY_USE_TESTNET = 'useXrplTestnet';

    private const CONFIG_KEY_MAINNET_ACCOUNT = 'xrplMainnetDestinationAccount';

    private const CONFIG_KEY_TESTNET_ACCOUNT = 'xrplTestnetDestinationAccount';

    private const CONFIG_KEY_RLUSD_ENABLED = 'xrplIsRlusdEnabled';

    private const CONFIG_KEY_USDC_ENABLED = 'xrplIsUsdcEnabled';

    private const CONFIG_KEY_QUOTE_EXPIRY = 'xrplQuoteExpiry';

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

    /**
     * Whether the plugin operates against the XRPL testnet (true) or mainnet (false).
     */
    public function isTest(): bool
    {
        return $this->getBool(self::CONFIG_KEY_USE_TESTNET, true);
    }

    /**
     * Returns the active XRPL network identifier: "testnet" or "mainnet".
     */
    public function getNetwork(): string
    {
        return $this->isTest() ? 'testnet' : 'mainnet';
    }

    /**
     * Returns the configured merchant receiving wallet address for the active network.
     */
    public function getDestinationAccount(): string
    {
        if ($this->isTest()) {
            return (string) $this->get(self::CONFIG_KEY_TESTNET_ACCOUNT);
        }

        return (string) $this->get(self::CONFIG_KEY_MAINNET_ACCOUNT);
    }

    /**
     * Whether RLUSD payments are enabled in the plugin configuration.
     */
    public function isRlusdEnabled(): bool
    {
        return $this->getBool(self::CONFIG_KEY_RLUSD_ENABLED, true);
    }

    /**
     * Whether USDC payments are enabled in the plugin configuration.
     */
    public function isUsdcEnabled(): bool
    {
        return $this->getBool(self::CONFIG_KEY_USDC_ENABLED, true);
    }

    /**
     * How long a price quote handed to the customer stays valid, in seconds.
     */
    public function getQuoteExpirySeconds(): int
    {
        return $this->getInt(self::CONFIG_KEY_QUOTE_EXPIRY, self::DEFAULT_QUOTE_EXPIRY_SECONDS);
    }
}
