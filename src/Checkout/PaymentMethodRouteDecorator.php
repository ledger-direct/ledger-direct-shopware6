<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Checkout;

use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * A LedgerDirect payment method is offered only while its chain is switched
 * on, has a receiving account, and the asset is enabled — the same question
 * the core asks before it quotes (ConfigProviderInterface::isAssetEnabled()).
 * Asked here, at the list of payment methods, it keeps a method the merchant
 * turned off out of the checkout instead of failing when the customer has
 * already chosen it. Methods that are not ours pass through untouched.
 */
class PaymentMethodRouteDecorator extends AbstractPaymentMethodRoute
{
    public function __construct(
        private readonly AbstractPaymentMethodRoute $decorated,
        private readonly ShopwareConfigProvider $configProvider,
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

        return $this->configProvider->isAssetEnabled($chain->chain, $asset->code);
    }
}
