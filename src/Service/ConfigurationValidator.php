<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Exception\InvalidConfigurationException;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;
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
        return [BeforeSystemConfigChangedEvent::class => 'onBeforeChange'];
    }

    public function onBeforeChange(BeforeSystemConfigChangedEvent $event): void
    {
        $chainAndNetwork = self::accountKeyFor($event->getKey());

        if ($chainAndNetwork === null) {
            return;
        }

        [$chain, $network] = $chainAndNetwork;
        $value = $event->getValue();

        if (!is_string($value)) {
            return;
        }

        $account = trim($value);
        $event->setValue($account);

        if ($account === '') {
            return;
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
