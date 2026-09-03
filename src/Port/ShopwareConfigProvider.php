<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use InvalidArgumentException;

/**
 * Platform side of {@see ConfigProviderInterface}: a thin facade over the
 * plugin's own ConfigurationService, which is where Shopware's system
 * configuration actually lives.
 */
class ShopwareConfigProvider implements ConfigProviderInterface
{
    public const CHAIN = 'XRPL';

    private ConfigurationService $configurationService;

    public function __construct(ConfigurationService $configurationService)
    {
        $this->configurationService = $configurationService;
    }

    public function getNetwork(string $chain): string
    {
        $this->assertChain($chain);

        return $this->configurationService->getNetwork();
    }

    public function getDestinationAccount(string $chain): string
    {
        $this->assertChain($chain);

        return $this->configurationService->getDestinationAccount();
    }

    /**
     * XRP is always accepted — it is the ledger's native asset and has no
     * trustline to set up, so there is nothing for a merchant to enable.
     * The stablecoins are opt-out via the plugin configuration.
     */
    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        $this->assertChain($chain);

        return match (strtoupper($baseAsset)) {
            'XRP' => true,
            'RLUSD' => $this->configurationService->isRlusdEnabled(),
            'USDC' => $this->configurationService->isUsdcEnabled(),
            default => false,
        };
    }

    public function getQuoteExpirySeconds(): int
    {
        return $this->configurationService->getQuoteExpirySeconds();
    }

    /**
     * The plugin only speaks XRPL. Asking for another chain is a wiring
     * mistake, not a configuration state, so it fails loudly rather than
     * silently answering with XRPL's settings.
     */
    private function assertChain(string $chain): void
    {
        if ($chain !== self::CHAIN) {
            throw new InvalidArgumentException(sprintf(
                'LedgerDirect supports chain "%s" only, got "%s".',
                self::CHAIN,
                $chain
            ));
        }
    }
}
