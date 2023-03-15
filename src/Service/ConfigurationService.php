<?php declare(strict_types=1);

namespace LedgerDirect\Service;

use XRPL_PHP\Core\Networks;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    private const CONFIG_DOMAIN = 'LedgerDirect';

    private const CONFIG_KEY_MAINNET_ACCOUNT = 'xrplMainnetAccount';

    private const CONFIG_KEY_TESTNET_ACCOUNT = 'xrplTestnetAccount';

    private SystemConfigService $systemConfigService;

    public function __construct(
        SystemConfigService $systemConfigService
    ) {
        $this->systemConfigService = $systemConfigService;
    }

    public function get(string $configName): mixed
    {
        $value = $this->systemConfigService->get(self::CONFIG_DOMAIN . '.config.' . $configName);
        if (empty($value)) {
            // TODO: Throw exception
        }

        return $value;
    }

    public function isTest(): bool
    {
        return $this->get('useTestnet');
    }

   public function getDestinationAccount(): string
   {
       if ($this->isTest()) {
           return $this->get(self::CONFIG_KEY_TESTNET_ACCOUNT);
       }

       return $this->get(self::CONFIG_KEY_MAINNET_ACCOUNT);
   }
}