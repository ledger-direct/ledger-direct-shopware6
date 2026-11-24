<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

/**
 * The payment handler for every Interledger asset — the chain's handler, as
 * XrplPaymentHandler and StellarPaymentHandler are for theirs. All the
 * behaviour is in the base class: quote on `pay`, redirect to the payment
 * page, read the record on `finalize`.
 *
 * Nothing of the Open Payments package is named here, on purpose: the
 * handler is a service in every installation, and the package is optional
 * (see Chain\ChainRegistry). A method of this handler only exists at all
 * once the merchant has switched the chain on.
 */
class InterledgerPaymentHandler extends AbstractLedgerDirectPaymentHandler
{
}
