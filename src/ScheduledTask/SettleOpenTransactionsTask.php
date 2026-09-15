<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * The safety net: syncs the ledger and settles every open LedgerDirect
 * order, on a schedule, whether or not anyone is looking at a payment page.
 *
 * Without it an order settles only while its payment page is open or the
 * customer comes back over the returnUrl. A customer who pays and closes
 * the tab would leave a paid order open forever, however often the
 * merchant re-ran the sync command — that command fills the transaction
 * table and matches nothing. PrestaShop has a cron endpoint for this;
 * Shopware's means is the scheduled task.
 */
class SettleOpenTransactionsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'ledger_direct.settle_open_transactions';
    }

    /** Every five minutes: the payment page polls faster, this only has to be sure. */
    public static function getDefaultInterval(): int
    {
        return 300;
    }
}
