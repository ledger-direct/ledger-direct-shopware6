<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\ScheduledTask;

use Hardcastle\LedgerDirect\Chain\ChainRegistry;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Port\ShopwareConfigProvider;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * The configured receiving account of every enabled chain is synced on every run, then one node
 * request per further receiving account and network of the open orders,
 * then every open order matched against the local transaction table — not one sync per
 * order. Unthrottled: this is the safety net on its own schedule, the
 * throttle is for the payment page that anyone can poll.
 *
 * Which further accounts to sync is read from the open orders themselves, not
 * only from the configuration: a shop with a test phase has orders on both networks,
 * and an order quoted against an earlier receiving address still has to
 * settle after the merchant changed it.
 *
 * Interledger has no account to sync: a payment is in the order's own incoming payment.
 * The open orders are read one by one in the match; an order the merchant cancelled is read
 * too while its incoming payment can still have taken money, so that what arrived is on the
 * order's record. Nothing reopens it: the state is the merchant's decision.
 */
#[AsMessageHandler(handles: SettleOpenTransactionsTask::class)]
final class SettleOpenTransactionsTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly OrderTransactionService $orderTransactionService,
        private readonly PaymentStateService $paymentState,
        private readonly ShopwareConfigProvider $configProvider,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    /**
     * How long after its incoming payment lapsed a cancelled order is still read. A lapsed
     * incoming payment takes nothing more, so one read after that moment is the last word;
     * an hour is twelve runs, enough for a wallet that was down for a while.
     */
    private const CANCELLED_READ_SECONDS = 3600;

    public function run(): void
    {
        $context = Context::createCLIContext();
        $transactions = $this->orderTransactionService->findOpenLedgerDirectTransactions($context);

        // The configured receiving account is synced on every run, open orders or not: a
        // payment on an order the merchant has already cancelled would otherwise never
        // reach the transaction table until some other order on the account happened to
        // trigger a sync. One node request.
        $accounts = [];
        foreach (PaymentMethodInstaller::supportedChains() as $chain) {
            $configuredAccount = $this->configProvider->getDestinationAccount($chain->chain);
            if ($configuredAccount !== '') {
                $configuredNetwork = $this->configProvider->getNetwork($chain->chain);
                $accounts[$chain->chain . '|' . $configuredNetwork . '|' . $configuredAccount] = [$chain->chain, $configuredAccount, $configuredNetwork];
            }
        }
        $matchable = [];

        /** @var OrderTransactionEntity $transaction */
        foreach ($transactions as $transaction) {
            $intent = $this->readIntent($transaction);

            if ($intent === null) {
                continue;
            }

            $accounts[$intent->chain . '|' . $intent->network . '|' . $intent->destinationAccount] = [$intent->chain, $intent->destinationAccount, $intent->network];
            $matchable[] = $transaction;
        }

        foreach ($accounts as [$chain, $account, $network]) {
            $this->orderTransactionService->syncLedger($chain, $account, $network, throttled: false);
        }

        $settled = 0;

        foreach ($matchable as $transaction) {
            try {
                $after = $this->paymentState->matchAndApply($transaction, $context);
            } catch (Throwable $exception) {
                // One order must not stop the others; the next run retries it.
                $this->exceptionLogger->error('LedgerDirect: could not settle an open order transaction', [
                    'order_transaction_id' => $transaction->getId(),
                    'exception' => $exception->getMessage(),
                ]);

                continue;
            }

            if (PaymentStateService::stateOf($after) === OrderTransactionStates::STATE_PAID) {
                ++$settled;
            }
        }

        $this->exceptionLogger->info('LedgerDirect: scheduled settlement run', [
            'accounts_synced' => count($accounts),
            'checked' => count($matchable),
            'settled' => $settled,
            'recorded_on_cancelled' => $this->recordPaymentsOnCancelledOrders($context),
        ]);
    }

    /**
     * What arrived in the incoming payment of an Interledger order the merchant cancelled is
     * written to the order's payment record, and nothing else changes: the order stays
     * cancelled, the merchant sees the money and decides. On a ledger the same payment is in
     * the transaction table after the account's sync above.
     *
     * @return int how many cancelled orders have a payment on record after this run's reads
     */
    private function recordPaymentsOnCancelledOrders(Context $context): int
    {
        $recorded = 0;

        /** @var OrderTransactionEntity $transaction */
        foreach ($this->orderTransactionService->findCancelledInterledgerTransactions($context) as $transaction) {
            $intent = $this->readIntent($transaction);

            if ($intent === null || $intent->chain !== ChainRegistry::INTERLEDGER || $intent->expiry === null) {
                continue;
            }

            if (time() > $intent->expiry + self::CANCELLED_READ_SECONDS) {
                continue;
            }

            try {
                if ($this->orderTransactionService->matchOrderTransaction($transaction, $context) !== null) {
                    ++$recorded;
                }
            } catch (Throwable $exception) {
                $this->exceptionLogger->error('LedgerDirect: could not read the incoming payment of a cancelled order', [
                    'order_transaction_id' => $transaction->getId(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $recorded;
    }

    private function readIntent(OrderTransactionEntity $transaction): ?\Hardcastle\LedgerDirect\Core\Payment\PaymentIntent
    {
        try {
            return $this->orderTransactionService->readPaymentIntent($transaction);
        } catch (Throwable $exception) {
            $this->exceptionLogger->error('LedgerDirect: open order transaction with an unreadable payment record skipped', [
                'order_transaction_id' => $transaction->getId(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
