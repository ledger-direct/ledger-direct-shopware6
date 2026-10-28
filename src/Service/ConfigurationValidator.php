<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Exception\InvalidConfigurationException;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigMultipleChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks a receiving account the moment it is saved, before it is stored.
 *
 * The format check is the core's (ChainDescriptor::isValidAccount(), an
 * `r…` address on the XRPL, a `G…` key on Stellar); it proves the string
 * has the shape of an account on that chain, not that the account exists or
 * belongs to the merchant. An invalid one is refused — a typo here sends
 * every customer's money nowhere. The value is trimmed first: an account is
 * copied from a wallet, and a stray space or line break is not part of it.
 *
 * The same account on two chains is logged, not refused: between the XRPL
 * and Stellar the formats make it impossible; the rule exists for a chain
 * that shares the XRPL's address format (Xahau), where a merchant may mean
 * it — but should see in the log that both chains will sync the same key.
 */
class ConfigurationValidator implements EventSubscriberInterface
{
    private const DOMAIN = 'LedgerDirect.config.';

    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeSystemConfigMultipleChangedEvent::class => 'onBeforeMultipleChange',
            BeforeSystemConfigChangedEvent::class => 'onBeforeChange',
        ];
    }

    /**
     * The administration saves a card as one batch. SystemConfigService
     * dispatches this event once, before it writes anything, and the
     * per-key event only inside its write loop — a refusal there would leave
     * the keys before the bad one already stored. So the whole batch is
     * checked here first; the per-key handler below then sees clean values.
     */
    public function onBeforeMultipleChange(BeforeSystemConfigMultipleChangedEvent $event): void
    {
        foreach ($event->getConfig() as $key => $value) {
            $normalised = $this->validate($key, $value);

            if ($normalised !== $value) {
                $event->setValue($key, $normalised);
            }
        }
    }

    /** A single set(), outside a batch. */
    public function onBeforeChange(BeforeSystemConfigChangedEvent $event): void
    {
        $normalised = $this->validate($event->getKey(), $event->getValue());

        if ($normalised !== $event->getValue()) {
            $event->setValue($normalised);
        }
    }

    /**
     * Returns the value to store — trimmed for an account key or a URL
     * template — or throws.
     */
    private function validate(string $key, mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($key, self::DOMAIN)) {
            $template = self::validateTemplate(substr($key, strlen(self::DOMAIN)), $value);

            if ($template !== null) {
                return $template;
            }
        }

        $chainAndNetwork = self::accountKeyFor($key);

        if ($chainAndNetwork === null || !is_string($value)) {
            return $value;
        }

        [$chain, $network] = $chainAndNetwork;
        $account = trim($value);

        if ($account === '') {
            return $account;
        }

        $descriptor = ChainCatalog::chain($chain);

        if (!$descriptor->isValidAccount($account)) {
            throw new InvalidConfigurationException(
                '"{{ account }}" is not a valid {{ chain }} account ({{ network }}). Check the receiving address in your wallet and paste it again.',
                ['account' => $account, 'chain' => $descriptor->label, 'network' => $network]
            );
        }

        foreach (ChainCatalog::chains() as $other) {
            if ($other->chain === $chain) {
                continue;
            }

            foreach ($other->networks as $otherNetwork) {
                if ($this->configuration->getAccount($other->chain, $otherNetwork) === $account) {
                    $this->logger->warning('LedgerDirect: the same receiving account is configured on two chains', [
                        'account' => $account,
                        'chains' => [$chain . '/' . $network, $other->chain . '/' . $otherNetwork],
                    ]);
                }
            }
        }

        return $account;
    }

    /**
     * The external front end's URL templates (HeadlessTargets): an absolute
     * URL with the placeholders the target needs — without {deepLinkCode} a
     * guest could never open the page it points to. Refused on save, like a
     * malformed account; cleared when emptied. Null for every other key.
     */
    private static function validateTemplate(string $short, string $value): ?string
    {
        $rules = [
            HeadlessTargets::PAYMENT_PAGE_KEY => [HeadlessTargets::PAYMENT_PAGE_PLACEHOLDERS, HeadlessTargets::PAYMENT_PAGE_REQUIRED],
            HeadlessTargets::ORDER_PAGE_KEY => [HeadlessTargets::ORDER_PAGE_PLACEHOLDERS, HeadlessTargets::ORDER_PAGE_REQUIRED],
        ];

        if (!isset($rules[$short])) {
            return null;
        }

        $template = trim($value);

        if ($template === '') {
            return '';
        }

        [$allowed, $required] = $rules[$short];
        $problem = HeadlessTargets::problem($template, $allowed, $required);

        if ($problem !== null) {
            throw new InvalidConfigurationException(
                'The URL template "{{ template }}" {{ problem }}.',
                ['template' => $template, 'problem' => $problem]
            );
        }

        return $template;
    }

    /**
     * `LedgerDirect.config.xrplMainnetDestinationAccount` → ['XRPL', 'mainnet'];
     * null for every other key.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function accountKeyFor(string $key): ?array
    {
        if (!str_starts_with($key, self::DOMAIN)) {
            return null;
        }

        $short = substr($key, strlen(self::DOMAIN));

        foreach (ChainCatalog::chains() as $chain) {
            foreach ($chain->networks as $network) {
                if ($short === strtolower($chain->chain) . ucfirst($network) . 'DestinationAccount') {
                    return [$chain->chain, $network];
                }
            }
        }

        return null;
    }
}
