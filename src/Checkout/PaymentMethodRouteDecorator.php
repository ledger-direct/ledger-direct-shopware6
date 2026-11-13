<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Checkout;

use Hardcastle\LedgerDirect\Chain\ChainRegistry;
use Hardcastle\LedgerDirect\Chain\InterledgerGateway;
use Hardcastle\LedgerDirect\Core\Stellar\AccountInspector;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * A LedgerDirect payment method is offered only while its chain is switched
 * on, has a receiving account, and the asset is enabled — the same question
 * the core asks before it quotes (ConfigProviderInterface::isAssetEnabled()) —
 * and, for a Stellar issued asset, while the receiving account provably has its trust line.
 * Asked here, at the list of payment methods, it keeps a method the merchant
 * turned off out of the checkout instead of failing when the customer has
 * already chosen it. Methods that are not ours pass through untouched.
 *
 * Interledger has a pre-flight question of its own: a wallet address holds
 * exactly one asset, so only the method in that asset can be paid at all.
 * The wallet is asked (and cached for minutes), and a wallet that cannot be
 * reached offers nothing — the same fail-closed rule as a trust line that
 * cannot be checked.
 */
class PaymentMethodRouteDecorator extends AbstractPaymentMethodRoute
{
    /** Our handlers, as the database holds them; it survives the loss of a chain's package. */
    private const HANDLER_NAMESPACE = 'Hardcastle\\LedgerDirect\\Components\\PaymentHandler\\';

    public function __construct(
        private readonly AbstractPaymentMethodRoute $decorated,
        private readonly ShopwareConfigProvider $configProvider,
        private readonly AccountInspector $accountInspector,
        private readonly InterledgerGateway $interledger,
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
        $methods = $response->getPaymentMethods()->filter(fn (PaymentMethodEntity $method): bool => $this->isOffered($method, $context));

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

    private function isOffered(PaymentMethodEntity $method, SalesChannelContext $context): bool
    {
        $chainAndAsset = PaymentMethodInstaller::chainAndAssetFor($method->getId());

        if ($chainAndAsset === null) {
            /*
             * Either not ours, or ours from a chain this installation no longer
             * knows: a merchant who removes the Interledger package keeps its
             * stored, active payment methods, and the chain list no longer maps
             * their ids. Offering one would let a customer choose a method that
             * cannot be quoted, so a method with one of our handlers stays out.
             * Everything else is another plugin's and passes untouched.
             */
            // Read through getVars(): the entity's handler identifier is a typed property,
            // and a route that loaded the collection with `includes` leaves it uninitialized,
            // which the getter answers with an Error.
            $handler = $method->getVars()['handlerIdentifier'] ?? null;

            return !is_string($handler) || !str_starts_with($handler, self::HANDLER_NAMESPACE);
        }

        [$chain, $asset] = $chainAndAsset;

        if (!$this->configProvider->isAssetEnabled($chain->chain, $asset->code)) {
            return false;
        }

        if ($chain->chain === ChainRegistry::INTERLEDGER) {
            if ($asset->code !== $this->interledger->walletAssetCode()) {
                return false;
            }

            // The prototype converts no fiat: a balance pegged to a currency takes orders in
            // that currency only (EUR for EUR, USD for USD and DSUSD), and quoting any other
            // would fail after the customer chose the method. An asset without a peg (XRP) is
            // priced by the oracles like on a ledger.
            return $asset->pegCurrency === null
                || strtoupper($asset->pegCurrency) === strtoupper($context->getCurrency()->getIsoCode());
        }

        if ($chain->chain !== ChainRegistry::STELLAR || $asset->native) {
            return true;
        }

        // A Stellar issued asset arrives only over a trust line on the receiving
        // account. Without one the payment fails in the customer's wallet, so the
        // method is not offered - and when Horizon cannot be asked, it is not
        // offered either: a method the shop cannot vouch for is worse than one
        // missing for a moment. XLM stays, the inspector caches good answers, and
        // the reason is logged.
        $network = $this->configProvider->getNetwork($chain->chain);
        $account = $this->configProvider->getDestinationAccount($chain->chain);
        $issuer = $asset->issuer($network);

        if ($issuer === null) {
            return false;
        }

        try {
            return $this->accountInspector->canReceive($account, $asset->code, $issuer, $network);
        } catch (Throwable $exception) {
            $this->logger->warning('LedgerDirect: could not check the trust line for a Stellar asset, not offering it', [
                'account' => $account,
                'asset' => $asset->code,
                'network' => $network,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
