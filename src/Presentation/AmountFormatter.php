<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;

/**
 * The one place amounts on the payment page are turned into strings.
 *
 * There used to be three renderings of the same number — the call to action
 * rounded to two places, the summary panel printed the raw float, the
 * settlement notice printed a third value — so the page asked the customer
 * for one amount and then declared a different one outstanding. On a small
 * order the difference exceeded the core's tolerance and the order never
 * settled, even though the customer had paid exactly what they were told.
 *
 * Nothing here rounds. The core already rounds where it must (five places
 * for XRP, two for the stablecoins, with BigDecimal), and rounding a second
 * time in the view is what caused the bug. This only turns numbers into
 * strings a human can read.
 */
final class AmountFormatter
{
    /**
     * How many decimals an exchange rate is shown with. The rate is an
     * average across oracles, so it arrives with the full float tail
     * (1.2017633333333) and is cosmetic beyond a few places.
     */
    private const RATE_DECIMALS = 6;

    /**
     * The amount the customer has to send, as the core states it: a plain
     * decimal, no exponent notation, no trailing zeros — for both a native
     * float amount and an issued currency's value.
     */
    public static function amountRequested(PaymentIntent $intent): string
    {
        return $intent->amountRequestedValue();
    }

    /**
     * What has arrived so far, by the same rule as the request — the core's
     * plain decimal of the delivered amount — or null while nothing has.
     * In the wrong-asset case this is the delivered token's value, so the
     * page can name what the customer actually sent.
     */
    public static function amountPaid(PaymentIntent $intent): ?string
    {
        return $intent->amountPaidValue();
    }

    /**
     * What is still due, in the requested asset, as the core states it:
     * null once settled, the whole request while nothing counted.
     */
    public static function shortfall(PaymentIntent $intent, SettlementPolicy $settlementPolicy): ?string
    {
        return $settlementPolicy->shortfall($intent);
    }

    /**
     * A float as a plain decimal string. PHP renders small floats in
     * exponent notation ("1.0E-5"), which on a payment page reads as an
     * amount nobody can send.
     */
    public static function rate(float $rate): string
    {
        return self::trimTrailingZeros(number_format($rate, self::RATE_DECIMALS, '.', ''));
    }

    private static function trimTrailingZeros(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
