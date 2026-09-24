<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Presentation\PaymentUri;
use PHPUnit\Framework\TestCase;

/**
 * The payment request behind the QR code, specified once for every platform.
 */
class PaymentUriTest extends TestCase
{
    private const ACCOUNT = 'rpgmK4KczivhfUv4iLLgFRANGE4gmyTgnr';

    private const TAG = 4294967295;

    public function testAnXrpRequestCarriesAccountAndTag(): void
    {
        $uri = PaymentUri::forIntent($this->xrp(0.83211), '0.83211');

        $this->assertSame(PaymentUri::BASE . '?to=' . self::ACCOUNT . '&dt=' . self::TAG, $uri);
    }

    /**
     * Until the scan test (PW-04) has shown how Xaman reads the amount, the
     * default request carries none — never an unverified amount.
     */
    public function testTheDefaultModeCarriesNoAmount(): void
    {
        $this->assertSame(PaymentUri::AMOUNT_NONE, PaymentUri::AMOUNT_MODE);
        $this->assertStringNotContainsString('amount=', PaymentUri::forIntent($this->xrp(0.83211), '0.83211'));
    }

    public function testTheDisplayedAmountIsPassedThroughUnchanged(): void
    {
        $uri = PaymentUri::forIntent($this->xrp(0.83211), '0.83211', PaymentUri::AMOUNT_DISPLAYED);

        $this->assertStringContainsString('&amount=0.83211', $uri);
    }

    /**
     * With a partial payment in, the request is for what is still due — the
     * page passes that in, the class does not decide it.
     */
    public function testAPartialPaymentRequestsTheShortfall(): void
    {
        $uri = PaymentUri::forIntent($this->xrp(0.83211), '0.33211', PaymentUri::AMOUNT_DISPLAYED);

        $this->assertStringContainsString('&amount=0.33211', $uri);
        $this->assertStringNotContainsString('0.83211', $uri);
    }

    public function testATokenRequestNamesCurrencyAndIssuer(): void
    {
        $uri = PaymentUri::forIntent($this->stablecoin('RLUSD', '524C555344000000000000000000000000000000', 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV', '84.75'), '84.75');

        $this->assertSame(
            PaymentUri::BASE . '?to=' . self::ACCOUNT . '&dt=' . self::TAG
            . '&currency=524C555344000000000000000000000000000000&issuer=rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
            $uri
        );
    }

    public function testATokenRequestWithAmountKeepsTheOrderOfParameters(): void
    {
        $uri = PaymentUri::forIntent($this->stablecoin('USDC', '5553444300000000000000000000000000000000', 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt', '1.16'), '1.16', PaymentUri::AMOUNT_DISPLAYED);

        $this->assertSame(
            PaymentUri::BASE . '?to=' . self::ACCOUNT . '&dt=' . self::TAG
            . '&amount=1.16&currency=5553444300000000000000000000000000000000&issuer=rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt',
            $uri
        );
    }

    /**
     * An issuer or account never contains reserved characters, but the
     * builder must encode rather than trust that.
     */
    public function testParametersAreEncoded(): void
    {
        $intent = PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 1.0,
            destinationAccount: 'r&=?#', destinationTag: 1,
        );

        $this->assertStringContainsString('to=r%26%3D%3F%23&dt=1', PaymentUri::forIntent($intent, '1'));
    }

    private function xrp(float $amount): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 1.2, amountRequested: $amount,
            destinationAccount: self::ACCOUNT, destinationTag: self::TAG,
        );
    }

    private function stablecoin(string $asset, string $currency, string $issuer, string $value): PaymentIntent
    {
        return PaymentIntent::quote(
            type: strtolower($asset) . '-payment', chain: 'XRPL', network: 'testnet', baseAsset: $asset, quoteCurrency: 'EUR',
            pairing: $asset . '/EUR', exchangeRate: 1.18,
            amountRequested: ['currency' => $currency, 'value' => $value, 'issuer' => $issuer],
            destinationAccount: self::ACCOUNT, destinationTag: self::TAG,
        );
    }
}
