<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\XRPL_PHP\Core\Networks;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationService
{
    private const CONFIG_DOMAIN = 'LedgerDirect';

    private const CONFIG_KEY_MAINNET_ACCOUNT = 'xrplMainnetAccount';

    private const CONFIG_KEY_MAINNET_TOKEN_NAME = 'xrplMainnetCustomTokenName';

    private const CONFIG_KEY_MAINNET_TOKEN_ISSUER = 'xrplMainnetCustomTokenIssuer';

    private const CONFIG_KEY_TESTNET_ACCOUNT = 'xrplTestnetAccount';

    private const CONFIG_KEY_TESTNET_TOKEN_NAME = 'xrplTestnetCustomTokenName';

    private const CONFIG_KEY_TESTNET_TOKEN_ISSUER = 'xrplTestnetCustomTokenIssuer';

    private SystemConfigService $systemConfigService;

    private LoggerInterface $logger;

    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

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

    public function isTest(): bool
    {
        return $this->get('useTestnet', true);
    }

    public function getNetwork(): string
    {
        return $this->isTest() ? 'testnet' : 'mainnet';
    }

   public function getDestinationAccount(): string
   {
       if ($this->isTest()) {
           return $this->get(self::CONFIG_KEY_TESTNET_ACCOUNT);
       }

       return $this->get(self::CONFIG_KEY_MAINNET_ACCOUNT);
   }

    public function getTokenName(): mixed
    {
        if ($this->isTest()) {
            return $this->get(self::CONFIG_KEY_TESTNET_TOKEN_NAME);
        }

        return $this->get(self::CONFIG_KEY_MAINNET_TOKEN_NAME);
    }

   public function getIssuer(): mixed
   {
       if ($this->isTest()) {
           return $this->get(self::CONFIG_KEY_TESTNET_TOKEN_ISSUER);
       }

       return $this->get(self::CONFIG_KEY_MAINNET_TOKEN_ISSUER);
   }

   /* public function isRlusdEnabled()
    {
        if ($this->isTest()) {
            return $this->get('xrplTestnetRlusdEnabled', false);
        }

        return $this->get('xrplMainnetRlusdEnabled', false);
    }
   */
}