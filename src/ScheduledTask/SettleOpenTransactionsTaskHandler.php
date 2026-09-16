<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\ScheduledTask;

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
 * One node request per receiving account and network, then every open
 * order matched against the local transaction table — not one sync per
 * order. Unthrottled: this is the safety net on its own schedule, the
 * throttle is for the payment page that anyone can poll.
 *
 * Which accounts to sync is read from the open orders themselves, not from
 * the configuration: a shop with a test phase has orders on both networks,
 * and an order quoted against an earlier receiving address still has to
 * settle after the merchant changed it.
 */
#[AsMessageHandler(handles: SettleOpenTransactionsTask::class)]
final class SettleOpenTransactionsTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly OrderTransactionService $orderTransactionService,
        private readonly PaymentStateService $paymentState,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $context = Context::createCLIContext();
        $transactions = $this->orderTransactionService->findOpenLedgerDirectTransactions($context);

        $accounts = [];
        $matchable = [];

        /** @var OrderTransactionEntity $transaction */
        foreach ($transactions as $transaction) {
            $intent = $this->readIntent($transaction);

            if ($intent === null) {
                continue;
            }

            $accounts[$intent->network . '|' . $intent->destinationAccount] = [$intent->destinationAccount, $intent->network];
            $matchable[] = $transaction;
        }

        foreach ($accounts as [$account, $network]) {
            $this->orderTransactionService->syncLedger($account, $network, throttled: false);
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
        ]);
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
