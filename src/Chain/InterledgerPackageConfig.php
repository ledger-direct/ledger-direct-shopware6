<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Chain;

use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Service\ConfigurationService;

/**
 * The shop's configuration as the Interledger package is told it: the
 * answers of ShopwareConfigProvider, except that the wallet address is the
 * configured one even while the chain is switched off.
 *
 * The switch decides whether new payments are taken, and for that
 * ShopwareConfigProvider answers '' for a chain that is off. A ledger chain
 * loses nothing by it: an open order's payment is found through the account
 * its intent names, switch or no switch. The package, however, reads an
 * order's incoming payment as the merchant's wallet and takes the wallet
 * address from the configuration to do so. Handed '' it could read nothing,
 * and an order that was paid just before the merchant switched the chain off
 * would stay open until it is switched on again.
 *
 * Only the address differs. `isAssetEnabled()` still says no for a chain
 * that is off, so the package refuses to quote: nothing new is offered.
 */
final class InterledgerPackageConfig implements ConfigProviderInterface
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly ShopwareConfigProvider $configProvider,
    ) {
    }

    public function getNetwork(string $chain): string
    {
        return $this->configProvider->getNetwork($chain);
    }

    public function getDestinationAccount(string $chain): string
    {
        if ($chain !== ChainRegistry::INTERLEDGER) {
            return $this->configProvider->getDestinationAccount($chain);
        }

        return $this->configuration->getDestinationAccount($chain);
    }

    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        return $this->configProvider->isAssetEnabled($chain, $baseAsset);
    }

    public function getQuoteExpirySeconds(): int
    {
        return $this->configProvider->getQuoteExpirySeconds();
    }
}
