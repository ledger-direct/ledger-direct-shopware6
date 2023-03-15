<?php

namespace LedgerDirect\Installer;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use LedgerDirect\Components\PaymentHandler\XrpPaymentHandler;
use LedgerDirect\LedgerDirect;

class PaymentMethodInstaller
{
    public const XRP_PAYMENT_ID = '7ca60321a9d2dac0fe3622a5110f55bb';


    private const PAYMENT_METHODS = [
        [
            'id' => self::XRP_PAYMENT_ID,
            'handlerIdentifier' => XrpPaymentHandler::class,
            'name' => 'XRP',
            'translations' => [
                'de-DE' => [
                    'name' => 'XRP',
                    'description' => 'Mit XRP bezahlen',
                ],
                'en-GB' => [
                    'name' => 'XRP',
                    'description' => 'Pay with XRP',
                ],
            ],
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
     * {@inheritdoc}
     */
    public function install(InstallContext $context): void
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $this->upsertPaymentMethod($paymentMethod, $context->getContext());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deactivate(DeactivateContext $context): void
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodStatus($paymentMethod, false, $context->getContext());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(UninstallContext $context): void
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
            $this->setPaymentMethodStatus($paymentMethod, false, $context->getContext());
        }
    }

    public function update(UpdateContext $context): void
    {
        //This function is not required yet.
    }

    public function activate(ActivateContext $context): void
    {
        foreach (self::PAYMENT_METHODS as $paymentMethod) {
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