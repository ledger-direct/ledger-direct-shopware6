<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Brings a preview chain's payment methods into being the moment the
 * merchant switches the chain on.
 *
 * A chain in PaymentMethodInstaller::PREVIEW_CHAINS has no payment methods
 * after install or update: nothing of it shows in Settings > Payment methods
 * for a merchant who never touched its card. When the card's switch
 * (`LedgerDirect.config.<chain>Enabled`) is saved as on, this creates and
 * activates the chain's methods - once; later saves find them in place and
 * change nothing. Switching the chain off leaves the methods alone, as it
 * does for a released chain: the checkout filter already hides them, and
 * orders may reference them.
 */
class PreviewChainActivator implements EventSubscriberInterface
{
    private const DOMAIN = 'LedgerDirect.config.';

    public function __construct(
        private readonly PaymentMethodInstaller $installer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigChangedEvent::class => 'onChanged'];
    }

    public function onChanged(SystemConfigChangedEvent $event): void
    {
        $chain = self::previewChainSwitchedOn($event->getKey(), $event->getValue());
        if ($chain === null) {
            return;
        }

        // The event carries no context, and the write is the system's, not a user's:
        // the installer wraps it in SYSTEM_SCOPE as plugin:install does.
        $this->installer->installChain($chain, Context::createCLIContext());
        $this->logger->info('LedgerDirect: preview chain switched on, its payment methods are in place', ['chain' => $chain]);
    }

    /**
     * `LedgerDirect.config.stellarEnabled` with a truthy value -> 'STELLAR';
     * null for every other key, value or chain.
     */
    public static function previewChainSwitchedOn(string $key, mixed $value): ?string
    {
        if (!str_starts_with($key, self::DOMAIN) || !$value) {
            return null;
        }

        $short = substr($key, strlen(self::DOMAIN));
        foreach (ChainCatalog::chains() as $chain) {
            if ($short === strtolower($chain->chain) . 'Enabled' && PaymentMethodInstaller::isPreviewChain($chain->chain)) {
                return $chain->chain;
            }
        }

        return null;
    }
}
