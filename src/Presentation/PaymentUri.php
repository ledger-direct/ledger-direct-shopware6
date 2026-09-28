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
 * token with `currency=<hex>&issuer=<address>`. Verified by scanning with
 * Xaman on the testnet (2026-09-28, PW-04): `amount` is read as the XRP
 * decimal the page shows — not as drops — and a token request takes the
 * currency and issuer over. So the request carries exactly the amount the
 * customer sees, the shortfall while a partial payment is in.
 */
final class PaymentUri
{
    public const BASE = 'https://xrplf.org//send';

    /** No amount in the request: the customer enters it from the page. */
    public const AMOUNT_NONE = 'none';

    /** The amount as the page shows it — XRP for the native asset, the token value otherwise. */
    public const AMOUNT_DISPLAYED = 'displayed';

    /** Since the scan test (PW-04): the amount as displayed. AMOUNT_NONE stays available for a platform that has not verified its wallets. */
    public const AMOUNT_MODE = self::AMOUNT_DISPLAYED;

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
