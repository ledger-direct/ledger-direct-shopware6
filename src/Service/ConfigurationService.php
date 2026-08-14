<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    private const CONFIG_DOMAIN = 'LedgerDirect';

    private const CONFIG_KEY_MAINNET_ACCOUNT = 'xrplMainnetDestinationAccount';

    private const CONFIG_KEY_TESTNET_ACCOUNT = 'xrplTestnetDestinationAccount';

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
     * Whether the plugin operates against the XRPL testnet (true) or mainnet (false).
     */
    public function isTest(): bool
    {
        return $this->get('useTestnet', true);
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
           return $this->get(self::CONFIG_KEY_TESTNET_ACCOUNT);
       }

       return $this->get(self::CONFIG_KEY_MAINNET_ACCOUNT);
   }

    /**
     * Whether RLUSD payments are enabled in the plugin configuration.
     */
   public function isRlusdEnabled()
    {
        if ($this->isTest()) {
            return $this->get('xrplRlusdEnabled', false);
        }

        return $this->get('xrplRlusdEnabled', false);
    }
}