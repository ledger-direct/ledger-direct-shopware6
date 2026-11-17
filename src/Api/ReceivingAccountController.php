<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

use Hardcastle\LedgerDirect\Core\Account\Finding;
use Hardcastle\LedgerDirect\Core\Account\ReceivingAccountCheck;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the configured receiving account of one chain lacks, for the chain's
 * card in the plugin configuration (INVARIANTS.md in the core, "Receiving
 * account"): no trust line for a token, not activated, deposit authorization.
 * The options stay visible; this says what stands in a customer's way.
 *
 * Read-only, reads the ledger afresh (and refreshes what the checkout reads).
 * The saved configuration is what counts — a value typed but not saved is not
 * what the checkout uses either. Whoever may read the configuration may read
 * this (`system_config:read`).
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReceivingAccountController extends AbstractController
{
    /**
     * @param array<string, ReceivingAccountCheck> $checks one per chain
     */
    public function __construct(
        private readonly ShopwareConfigProvider $configProvider,
        private readonly array $checks,
    ) {
    }

    #[Route(
        path: '/api/_action/ledger-direct/receiving-account/{chain}',
        name: 'api.action.ledger-direct.receiving-account',
        defaults: ['_acl' => ['system_config:read']],
        methods: ['GET']
    )]
    public function check(string $chain): JsonResponse
    {
        $chain = strtoupper($chain);
        $check = $this->checks[$chain] ?? null;

        if ($check === null || !ChainCatalog::has($chain)) {
            return new JsonResponse(['error' => 'unknown_chain'], Response::HTTP_NOT_FOUND);
        }

        $account = $this->configProvider->getDestinationAccount($chain);
        $network = $this->configProvider->getNetwork($chain);

        if ($account === '') {
            // Chain off or no account: nothing is offered, nothing to check.
            return new JsonResponse(['chain' => $chain, 'nativeAsset' => ChainCatalog::chain($chain)->nativeAsset, 'network' => $network, 'account' => null, 'findings' => []]);
        }

        $assets = [];
        foreach (ChainCatalog::chain($chain)->assets as $asset) {
            if ($this->configProvider->isAssetEnabled($chain, $asset->code)) {
                $assets[] = $asset->code;
            }
        }

        $assessment = $check->assess($account, $network, $assets);

        return new JsonResponse([
            'chain' => $chain,
            'nativeAsset' => ChainCatalog::chain($chain)->nativeAsset,
            'network' => $network,
            'account' => $account,
            'findings' => array_map(static fn (Finding $finding): array => [
                'code' => $finding->code,
                'asset' => $finding->asset,
                'blocksOffer' => $finding->blocksOffer,
                'context' => $finding->context,
            ], $assessment->findings),
        ]);
    }
}
