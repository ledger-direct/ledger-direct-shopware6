<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Installer;

use Hardcastle\LedgerDirect\Components\PaymentHandler\RlusdPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\StellarPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\UsdcPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\XrplPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\XrpPaymentHandler;
use Hardcastle\LedgerDirect\Core\AssetDescriptor;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\ChainDescriptor;
use Hardcastle\LedgerDirect\LedgerDirect;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

/**
 * One payment method per (chain, asset) the core's ChainCatalog lists for a
 * chain this plugin has a handler for. The catalogue is the only list of
 * assets; what the plugin holds is the mapping onto Shopware's objects: the
 * payment method id, the technical name, the handler per chain.
 *
 * The three XRPL methods keep the ids they have had since the first release
 * — orders reference them. Every later method derives its id from the
 * intent type (`md5('stellar-usdc-payment')`), so it is the same on every
 * installation without a constant per asset.
 */
class PaymentMethodInstaller
{
    public const XRP_PAYMENT_ID = '7ca60321a9d2dac0fe3622a5110f55bb';
    public const TOKEN_PAYMENT_ID = '7ca60321a9d2dac0fe3622a5110f55bc';
    public const RLUSD_PAYMENT_ID = '7ca60321a9d2dac0fe3622a5110f55bd';
    public const USDC_PAYMENT_ID = '7ca60321a9d2dac0fe3622a5110f55be';

    /**
     * The chains this plugin can take payments on, each with its handler.
     * A chain in the catalogue that is missing here is not offered — and
     * the completeness test says so, loudly, until it is added.
     */
    public const HANDLER_BY_CHAIN = [
        ChainCatalog::XRPL => XrplPaymentHandler::class,
        ChainCatalog::STELLAR => StellarPaymentHandler::class,
    ];

    /** Ids from before the catalogue, kept for the orders that carry them. */
    private const LEGACY_ID_BY_TYPE = [
        'xrp-payment' => self::XRP_PAYMENT_ID,
        'rlusd-payment' => self::RLUSD_PAYMENT_ID,
        'usdc-payment' => self::USDC_PAYMENT_ID,
    ];

    /**
     * The three XRPL methods keep the handler identifiers they were created
     * with — the administration's order card and the storefront's icon
     * mapping know them by name, and both ship as built assets. The classes
     * are the chain handler under their old names. Every later method gets
     * HANDLER_BY_CHAIN.
     */
    private const LEGACY_HANDLER_BY_TYPE = [
        'xrp-payment' => XrpPaymentHandler::class,
        'rlusd-payment' => RlusdPaymentHandler::class,
        'usdc-payment' => UsdcPaymentHandler::class,
    ];

    /** Names and descriptions as the three XRPL methods have always shown them. */
    private const TRANSLATIONS_BY_TYPE = [
        'xrp-payment' => [
            'de-DE' => ['name' => 'XRP (XRPL)', 'description' => 'Mit XRP bezahlen'],
            'en-GB' => ['name' => 'XRP on XRPL', 'description' => 'Pay with XRP'],
        ],
        'rlusd-payment' => [
            'de-DE' => ['name' => 'RLUSD (XRPL)', 'description' => 'Mit RLUSD (XRPL) bezahlen'],
            'en-GB' => ['name' => 'RLUSD on XRPL', 'description' => 'Pay with RLUSD (XRPL)'],
        ],
        'usdc-payment' => [
            'de-DE' => ['name' => 'USDC (XRPL)', 'description' => 'Mit USDC (XRPL) bezahlen'],
            'en-GB' => ['name' => 'USDC on XRPL', 'description' => 'Pay with USDC (XRPL)'],
        ],
    ];

    private EntityRepository $paymentMethodRepository;

    private PluginIdProvider $pluginIdProvider;

    public function __construct(
        EntityRepository $paymentMethodRepository,
        PluginIdProvider $pluginIdProvider
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->pluginIdProvider = $pluginIdProvider;
    }

    /**
     * The chains the plugin offers, in catalogue order.
     *
     * @return list<ChainDescriptor>
     */
    public static function supportedChains(): array
    {
        return array_values(array_filter(
            ChainCatalog::chains(),
            static fn (ChainDescriptor $chain): bool => isset(self::HANDLER_BY_CHAIN[$chain->chain]),
        ));
    }

    /**
     * The payment method id for an intent type — `xrp-payment` → the id from
     * the first release, `stellar-usdc-payment` → derived from the type.
     */
    public static function paymentMethodIdFor(string $type): string
    {
        return self::LEGACY_ID_BY_TYPE[$type] ?? md5($type);
    }

    /**
     * The chain and asset a payment method id stands for, or null for a
     * method that is not one of ours.
     *
     * @return array{0: ChainDescriptor, 1: AssetDescriptor}|null
     */
    public static function chainAndAssetFor(?string $paymentMethodId): ?array
    {
        if ($paymentMethodId === null) {
            return null;
        }

        foreach (self::supportedChains() as $chain) {
            foreach ($chain->assets as $asset) {
                if (self::paymentMethodIdFor($asset->type) === $paymentMethodId) {
                    return [$chain, $asset];
                }
            }
        }

        return null;
    }

    /**
     * Every payment method id of ours, for queries over order transactions.
     *
     * @return list<string>
     */
    public static function paymentMethodIds(): array
    {
        return array_map(static fn (array $method): string => $method['id'], self::paymentMethods());
    }

    /**
     * The payment methods as the repository takes them.
     *
     * @return list<array<string, mixed>>
     */
    public static function paymentMethods(): array
    {
        $methods = [];

        foreach (self::supportedChains() as $chain) {
            foreach ($chain->assets as $asset) {
                $methods[] = [
                    'id' => self::paymentMethodIdFor($asset->type),
                    'handlerIdentifier' => self::LEGACY_HANDLER_BY_TYPE[$asset->type] ?? self::HANDLER_BY_CHAIN[$chain->chain],
                    'name' => $asset->code,
                    'technicalName' => 'ledgerdirect_' . strtolower($chain->chain) . '_' . strtolower($asset->code),
                    'translations' => self::TRANSLATIONS_BY_TYPE[$asset->type] ?? [
                        'de-DE' => ['name' => $asset->code . ' (' . $chain->label . ')', 'description' => 'Mit ' . $asset->code . ' (' . $chain->label . ') bezahlen'],
                        'en-GB' => ['name' => $asset->code . ' on ' . $chain->label, 'description' => 'Pay with ' . $asset->code . ' (' . $chain->label . ')'],
                    ],
                ];
            }
        }

        return $methods;
    }

    public function install(InstallContext $context): void
    {
        foreach (self::paymentMethods() as $paymentMethod) {
            $this->upsertPaymentMethod($paymentMethod, $context->getContext());
        }
    }

    /**
     * An update writes the methods again: a new chain or asset in the
     * catalogue gets its method, an existing one its current name and
     * technical name. Active flags are left as the merchant set them.
     */
    public function update(UpdateContext $context): void
    {
        foreach (self::paymentMethods() as $paymentMethod) {
            $this->upsertPaymentMethod($paymentMethod, $context->getContext());
        }
    }

    public function deactivate(DeactivateContext $context): void
    {
        foreach (self::paymentMethods() as $paymentMethod) {
            $this->setPaymentMethodStatus($paymentMethod, false, $context->getContext());
        }
    }

    public function uninstall(UninstallContext $context): void
    {
        foreach (self::paymentMethods() as $paymentMethod) {
            $this->setPaymentMethodStatus($paymentMethod, false, $context->getContext());
        }
    }

    public function activate(ActivateContext $context): void
    {
        foreach (self::paymentMethods() as $paymentMethod) {
            $this->setPaymentMethodStatus($paymentMethod, true, $context->getContext());
        }
    }

    private function upsertPaymentMethod(array $paymentMethod, Context $context): void
    {
        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(LedgerDirect::class, $context);
        $paymentMethod['pluginId'] = $pluginId;

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($paymentMethod): void {
            $this->paymentMethodRepository->upsert([$paymentMethod], $context);
        });
    }

    private function setPaymentMethodStatus(array $paymentMethod, bool $active, Context $context): void
    {
        $paymentMethodCriteria = new Criteria([$paymentMethod['id']]);
        $hasPaymentMethod = $this->paymentMethodRepository->searchIds($paymentMethodCriteria, $context)->getTotal() > 0;

        if (!$hasPaymentMethod) {
            return;
        }

        $data = [
            'id' => $paymentMethod['id'],
            'active' => $active,
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($data): void {
            $this->paymentMethodRepository->upsert([$data], $context);
        });
    }
}
