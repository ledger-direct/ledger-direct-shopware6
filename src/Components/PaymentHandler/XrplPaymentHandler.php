<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

/**
 * The payment handler of every XRP Ledger payment method — XRP, RLUSD, USDC.
 * Which asset an order is quoted in is the payment method's business
 * (PaymentMethodInstaller::chainAndAssetFor()), not the handler's; all
 * behaviour lives in {@see AbstractLedgerDirectPaymentHandler}. One handler
 * per chain, so a new asset in the core's catalogue needs no new class here.
 */
class XrplPaymentHandler extends AbstractLedgerDirectPaymentHandler
{
}
