<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Checkout;

use Hardcastle\LedgerDirect\Core\Account\ReceivingAccountCheck;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * A LedgerDirect payment method is offered only while its chain is switched
 * on, has a receiving account, and the asset is enabled — the same question
 * the core asks before it quotes (ConfigProviderInterface::isAssetEnabled()) —
 * and while the receiving account can take the asset (the core's
 * ReceivingAccountCheck::offers(): a trust line for a token, an account that
 * exists on Stellar, and so on; INVARIANTS.md, "Receiving account").
 * Asked here, at the list of payment methods, it keeps a method nobody could
 * pay out of the checkout instead of failing after the order exists — on the
 * testnet a shop offered RLUSD to an account without a trust line and the
 * customer's payment failed with tecPATH_DRY. Methods that are not ours pass
 * through untouched.
 */
class PaymentMethodRouteDecorator extends AbstractPaymentMethodRoute
{
    /**
     * @param array<string, ReceivingAccountCheck> $checks one per chain, keyed 'XRPL', 'XAHAU', 'STELLAR'
     */
    public function __construct(
        private readonly AbstractPaymentMethodRoute $decorated,
        private readonly ShopwareConfigProvider $configProvider,
        private readonly array $checks,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getDecorated(): AbstractPaymentMethodRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): PaymentMethodRouteResponse
    {
        $response = $this->decorated->load($request, $context, $criteria);
        $methods = $response->getPaymentMethods()->filter(fn (PaymentMethodEntity $method): bool => $this->isOffered($method));

        // The response wraps an EntitySearchResult that carries the elements and the
        // total of its own, next to the entity collection: the Store API serialises
        // those, the storefront reads the collection. Both have to agree, so this is
        // assigned the way PaymentMethodRoute itself does after its rule filter.
        $response->getObject()->assign([
            'entities' => $methods,
            'elements' => $methods->getElements(),
            'total' => $methods->count(),
        ]);

        return $response;
    }

    private function isOffered(PaymentMethodEntity $method): bool
    {
        $chainAndAsset = PaymentMethodInstaller::chainAndAssetFor($method->getId());

        if ($chainAndAsset === null) {
            return true;
        }

        [$chain, $asset] = $chainAndAsset;

        if (!$this->configProvider->isAssetEnabled($chain->chain, $asset->code)) {
            return false;
        }

        $check = $this->checks[$chain->chain] ?? null;
        if ($check === null) {
            // A chain without its check (a wiring mistake, caught by ReceivingAccountCheckWiringTest)
            // gets the rule for an unreachable node: tokens out, the native asset stays.
            $this->logger->error('LedgerDirect: no receiving-account check for this chain', ['chain' => $chain->chain]);

            return $asset->native;
        }

        $account = $this->configProvider->getDestinationAccount($chain->chain);
        $network = $this->configProvider->getNetwork($chain->chain);

        // The amount is not known yet at the list of methods: an unactivated XRPL account
        // keeps XRP (the first payment from the base reserve up activates it), and the
        // configuration screen warns the merchant.
        try {
            return $check->offers($account, $asset->code, $network);
        } catch (Throwable $exception) {
            // offers() does not throw for a node it cannot reach; anything else is a bug.
            // Same rule as for an unreachable node: tokens out, the native asset stays.
            $this->logger->error('LedgerDirect: checking the receiving account failed', [
                'chain' => $chain->chain,
                'asset' => $asset->code,
                'exception' => $exception->getMessage(),
            ]);

            return $asset->native;
        }
    }
}
