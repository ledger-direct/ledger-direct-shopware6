<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Presentation\AmountFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountFormatterTest extends TestCase
{
    /**
     * The regression: the page rounded the quote to two places for the call to
     * action while quoting five, so the customer paid 0.83 against a request of
     * 0.83211 and the order never settled — the missing 0.2536% is well past
     * the core's 0.15% tolerance.
     */
    public function testTheRequestedAmountIsShownExactlyAsQuoted(): void
    {
        $this->assertSame('0.83211', AmountFormatter::amountRequested($this->givenXrpIntent(0.83211)));
    }

    /**
     * PHP renders small floats in exponent notation, and "please send 1.0E-5
     * XRP" is an instruction nobody can follow.
     */
    public function testASmallAmountIsNotShownInExponentNotation(): void
    {
        $formatted = AmountFormatter::amountRequested($this->givenXrpIntent(0.00001));

        $this->assertSame('0.00001', $formatted);
        $this->assertStringNotContainsStringIgnoringCase('e', $formatted);
    }

    public function testTrailingZerosAreDropped(): void
    {
        $this->assertSame('40', AmountFormatter::amountRequested($this->givenXrpIntent(40.0)));
        $this->assertSame('0.5', AmountFormatter::amountRequested($this->givenXrpIntent(0.5)));
    }

    /**
     * A stablecoin amount is quoted as an issued currency; the customer is
     * shown the value out of that envelope, unchanged.
     */
    public function testAnIssuedCurrencyIsShownByItsValue(): void
    {
        $this->assertSame('84.75', AmountFormatter::amountRequested($this->givenStablecoinIntent('84.75')));
    }

    #[DataProvider('rates')]
    public function testRateFormatting(float $rate, string $expected): void
    {
        $this->assertSame($expected, AmountFormatter::rate($rate));
    }

    public static function rates(): array
    {
        return [
            'oracle average keeps six places' => [1.2017633333333, '1.201763'],
            'whole number stays short' => [2.0, '2'],
            'trailing zeros dropped' => [2.5, '2.5'],
            'no exponent for small rates' => [0.000001, '0.000001'],
        ];
    }

    private function givenXrpIntent(float $amountRequested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 1.2017633333333,
            amountRequested: $amountRequested,
            destinationAccount: 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr',
            destinationTag: 114729,
        );
    }

    private function givenStablecoinIntent(string $value): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'EUR',
            pairing: 'RLUSD/EUR',
            exchangeRate: 1.18,
            amountRequested: [
                'currency' => '524C555344000000000000000000000000000000',
                'value' => $value,
                'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
            ],
            destinationAccount: 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr',
            destinationTag: 114729,
        );
    }
}
