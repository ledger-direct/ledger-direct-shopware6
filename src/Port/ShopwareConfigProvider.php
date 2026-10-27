<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Port;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Service\ConfigurationService;

/**
 * Platform side of {@see ConfigProviderInterface}: a thin facade over the
 * plugin's own ConfigurationService, which is where Shopware's system
 * configuration actually lives.
 *
 * Chain-generic since 1.6.0. The port has taken `$chain` since core 0.1; the
 * plugin used to refuse everything but XRPL. Now every chain the core's
 * ChainCatalog lists is answered from its own configuration card, and
 * "chain switched off" is expressed through the port as it always could
 * be: no receiving account, no asset enabled. The core needs no notion of
 * an enabled chain for that.
 */
class ShopwareConfigProvider implements ConfigProviderInterface
{
    /** The chain the plugin's XRPL-only entry points (console commands) still speak. */
    public const CHAIN = ChainCatalog::XRPL;

    private ConfigurationService $configurationService;

    public function __construct(ConfigurationService $configurationService)
    {
        $this->configurationService = $configurationService;
    }

    /**
     * The chain's active network. A chain the catalogue does not know gets
     * 'testnet': the safe answer for a question nothing should be asking.
     */
    public function getNetwork(string $chain): string
    {
        if (!ChainCatalog::has($chain)) {
            return 'testnet';
        }

        return $this->configurationService->getNetwork($chain);
    }

    /**
     * The receiving account on the chain's active network — '' when the
     * chain is switched off or unknown, so nothing is quoted against it.
     */
    public function getDestinationAccount(string $chain): string
    {
        if (!ChainCatalog::has($chain) || !$this->configurationService->isChainEnabled($chain)) {
            return '';
        }

        return $this->configurationService->getDestinationAccount($chain);
    }

    /**
     * False for a chain that is off, unknown, or has no receiving account,
     * and for an asset the catalogue does not list on that chain. The
     * native asset is on whenever the chain is — it has no trust line to
     * set up, so there is nothing for a merchant to enable. Every other
     * asset follows its own toggle, on by default.
     */
    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        if ($this->getDestinationAccount($chain) === '') {
            return false;
        }

        $descriptor = ChainCatalog::chain($chain);
        $code = strtoupper($baseAsset);

        if (!$descriptor->hasAsset($code)) {
            return false;
        }

        if ($descriptor->asset($code)->native) {
            return true;
        }

        return $this->configurationService->isAssetEnabled($chain, $code);
    }

    public function getQuoteExpirySeconds(): int
    {
        return $this->configurationService->getQuoteExpirySeconds();
    }
}
