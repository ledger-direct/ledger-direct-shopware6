<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * The payment request the QR code encodes: one code with the receiving
 * account, the destination tag and — once the format is verified — the
 * amount, so the customer types nothing. Typing the tag was the biggest
 * source of lost payments.
 *
 * Built on the server so it is unit-testable and specified once for every
 * platform; the script only renders it.
 *
 * The form is the one Xaman's parser (xumm-string-decode) accepts:
 * `https://xrplf.org//send?to=<account>&dt=<tag>&amount=<amount>`, for a
 * token with `currency=<hex>&issuer=<address>`. What the parser has NOT
 * been shown to do yet is read the amount unambiguously — as XRP, or as
 * drops — and take a token over. Until that scan test is done, AMOUNT_MODE
 * is NONE: address and tag only, never an unverified amount. A QR code
 * with the wrong unit would be a real money error.
 */
final class PaymentUri
{
    public const BASE = 'https://xrplf.org//send';

    /** No amount in the request: the customer enters it from the page. */
    public const AMOUNT_NONE = 'none';

    /** The amount as the page shows it — XRP for the native asset, the token value otherwise. */
    public const AMOUNT_DISPLAYED = 'displayed';

    /** Pending the scan test (PW-04); switch to AMOUNT_DISPLAYED once Xaman reads it as XRP. */
    public const AMOUNT_MODE = self::AMOUNT_NONE;

    /**
     * @param string $amountDue the amount to send, as the page shows it: the
     *     shortfall while a partial payment is in, the request otherwise
     */
    public static function forIntent(PaymentIntent $intent, string $amountDue, string $amountMode = self::AMOUNT_MODE): string
    {
        $parameters = [
            'to' => $intent->destinationAccount,
            'dt' => (string) $intent->destinationTag,
        ];

        if ($amountMode === self::AMOUNT_DISPLAYED && $amountDue !== '') {
            $parameters['amount'] = $amountDue;
        }

        if (is_array($intent->amountRequested)) {
            $parameters['currency'] = (string) $intent->amountRequested['currency'];
            $parameters['issuer'] = (string) $intent->amountRequested['issuer'];
        }

        return self::BASE . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
