<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

/**
 * The payment handler of every Stellar payment method — XLM, USDC, EURC.
 * As with the XRPL, which asset an order is quoted in is the payment
 * method's business; all behaviour lives in {@see AbstractLedgerDirectPaymentHandler}.
 */
class StellarPaymentHandler extends AbstractLedgerDirectPaymentHandler
{
}
