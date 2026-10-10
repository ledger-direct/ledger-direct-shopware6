<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Presentation\Explorer;
use Hardcastle\LedgerDirect\Presentation\PaymentInfoPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The administration's payment card: the core's status payload untouched,
 * the display strings formatted on the server, the rows of Magento's
 * Payment Information.
 */
class PaymentInfoPresenterTest extends TestCase
{
    private const RLUSD = ['currency' => '524C555344000000000000000000000000000000', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'];
    private const USDC = ['currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    public function testAWaitingOrderShowsTheQuoteAndItsValidityAndNoPayment(): void
    {
        $intent = $this->xrpQuote(expiry: 1_800_000_000);

        $view = PaymentInfoPresenter::present($intent, new SettlementPolicy());

        self::assertSame('waiting', $view['status']['state']);
        self::assertSame(1, $view['status']['schema_version']);
        self::assertSame('XRP', $view['display']['asset']);
        self::assertSame('testnet', $view['display']['network']);
        self::assertSame('40', $view['display']['amountRequested']);
        self::assertSame('2.5', $view['display']['exchangeRate']);
        self::assertSame('XRP/EUR', $view['display']['pairing']);
        self::assertSame('rMerchant', $view['display']['destinationAccount']);
        self::assertSame(114729, $view['display']['destinationTag']);
        self::assertNull($view['display']['issuer']);
        self::assertSame('2027-01-15T08:00:00+00:00', $view['display']['quoteValidUntil']);
        self::assertNull($view['display']['amountPaid']);
        self::assertSame('40', $view['display']['shortfall'], 'nothing credited, the whole request is due');
        self::assertFalse($view['display']['wrongAsset']);
        self::assertNull($view['display']['paidAsset']);
        self::assertNull($view['display']['hash']);
        self::assertNull($view['display']['explorerUrl']);
    }

    public function testAPartialPaymentShowsWhatArrivedWhatIsDueAndTheTransaction(): void
    {
        $intent = $this->xrpQuote()->withFulfillment('HASH-HALF', 15.0, 'C0000001');

        $view = PaymentInfoPresenter::present($intent, new SettlementPolicy());

        self::assertSame('partial', $view['status']['state']);
        self::assertSame('15', $view['display']['amountPaid']);
        self::assertSame('25', $view['display']['shortfall']);
        self::assertNull($view['display']['quoteValidUntil'], 'the quote is spent once something arrived');
        self::assertSame('HASH-HALF', $view['display']['hash']);
        self::assertSame('C0000001', $view['display']['ctid']);
        self::assertSame('https://testnet.xrpl.org/transactions/HASH-HALF', $view['display']['explorerUrl']);
    }

    public function testAWrongTokenNamesWhatArrivedAndItsIssuer(): void
    {
        $intent = $this->usdcQuote()->withFulfillment('HASH-WRONG', self::RLUSD + ['value' => '40']);

        $view = PaymentInfoPresenter::present($intent, new SettlementPolicy());

        self::assertSame('wrong_asset', $view['status']['state']);
        self::assertTrue($view['display']['wrongAsset']);
        self::assertSame(['currency' => 'RLUSD', 'issuer' => self::RLUSD['issuer']], $view['display']['paidAsset']);
        self::assertSame('40', $view['display']['amountPaid']);
        self::assertSame('40', $view['display']['shortfall'], 'the whole request, nothing credited, as the plain decimal the core states');
        self::assertSame(self::USDC['issuer'], $view['display']['issuer']);
    }

    public function testXrpOnATokenOrderIsTheWrongAssetWithXrpAsThePaidAsset(): void
    {
        $intent = $this->usdcQuote()->withFulfillment('HASH-XRP', 40.0);

        $view = PaymentInfoPresenter::present($intent, new SettlementPolicy());

        self::assertSame('wrong_asset', $view['status']['state']);
        self::assertSame(['currency' => 'XRP', 'issuer' => null], $view['display']['paidAsset']);
    }

    public function testASettledOrderHasNoShortfallAndAMainnetExplorerLink(): void
    {
        $intent = PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'mainnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 40.0, destinationAccount: 'rMerchant', destinationTag: 1, expiry: time() + 300,
        )->withFulfillment('HASH-FULL', 40.0, 'C0000002');

        $view = PaymentInfoPresenter::present($intent, new SettlementPolicy());

        self::assertSame('settled', $view['status']['state']);
        self::assertNull($view['display']['shortfall']);
        self::assertNull($view['display']['quoteValidUntil']);
        self::assertSame('https://livenet.xrpl.org/transactions/HASH-FULL', $view['display']['explorerUrl']);
    }

    public function testCurrencyNamesDecodeTheHexFormAndLeaveTheRestAlone(): void
    {
        self::assertSame('RLUSD', PaymentInfoPresenter::currencyName(self::RLUSD['currency']));
        self::assertSame('USDC', PaymentInfoPresenter::currencyName(self::USDC['currency']));
        self::assertSame('XRP', PaymentInfoPresenter::currencyName('XRP'));
        self::assertSame('0000000000000000000000000000000000000000', PaymentInfoPresenter::currencyName('0000000000000000000000000000000000000000'));
    }

    public function testTheExplorerKnowsTwoNetworksAndNothingElse(): void
    {
        self::assertSame('https://testnet.xrpl.org/transactions/ABC', Explorer::transactionUrl('testnet', 'ABC'));
        self::assertSame('https://livenet.xrpl.org/transactions/ABC', Explorer::transactionUrl('mainnet', 'ABC'));
        self::assertNull(Explorer::transactionUrl('devnet', 'ABC'));
        self::assertNull(Explorer::transactionUrl('testnet', null));
    }

    private function xrpQuote(?int $expiry = null): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 40.0, destinationAccount: 'rMerchant', destinationTag: 114729,
            expiry: $expiry ?? time() + 300,
        );
    }

    private function usdcQuote(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'usdc-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'USDC', quoteCurrency: 'USD',
            pairing: 'USDC/USD', exchangeRate: 1.0, amountRequested: self::USDC + ['value' => '40.00'],
            destinationAccount: 'rMerchant', destinationTag: 114729, expiry: time() + 300,
        );
    }
}
