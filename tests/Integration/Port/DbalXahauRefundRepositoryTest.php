<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Integration\Port;

/**
 * Every case of DbalRefundRepositoryTest again, on Xahau's refund table:
 * the core's contract, the table as the core describes it, and nothing
 * crossing over to the XRP Ledger's.
 */
class DbalXahauRefundRepositoryTest extends DbalRefundRepositoryTest
{
    protected function chain(): string
    {
        return 'XAHAU';
    }
}
