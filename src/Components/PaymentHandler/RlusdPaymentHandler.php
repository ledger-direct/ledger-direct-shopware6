<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

/**
 * Payment handler for RLUSD payments on the XRP Ledger. All behaviour lives in
 * {@see AbstractLedgerDirectPaymentHandler}; this class exists so the payment method has a
 * handler identifier of its own.
 */
class RlusdPaymentHandler extends AbstractLedgerDirectPaymentHandler
{
}
