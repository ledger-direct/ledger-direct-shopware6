<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Stellar\StellarPayment;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use Hardcastle\LedgerDirect\Presentation\ReceivedPaymentsPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The rows under "Received payments": one per stored ledger payment, as the
 * ledger delivered it, marked when it is not in the quoted asset.
 */
class ReceivedPaymentsPresenterTest extends TestCase
{
    private const RLUSD = ['currency' => '524C555344000000000000000000000000000000', 'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV'];
    private const USDC = ['currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    private const STELLAR_USDC_ISSUER = 'GBBD47IF6LWK7P7MDEVSCWR7DPUWV3NY3DTQEVFL4NAT4AQH3ZLLFLA5';

    /**
     * A row of the core's Xahau fixture (tests/Xahau/fixtures/account-tx.json), validated on
     * the Xahau testnet on 2026-11-06. It pins the date: the ledger counts from 2000, and
     * read as Unix time the same number is a day in 1996.
     */
    public function testARowIsThePaymentAsTheLedgerRecordedIt(): void
    {
        $view = ReceivedPaymentsPresenter::present($this->xahQuote(), [new XrplTransaction(
            network: 'testnet',
            ledgerIndex: '13833890',
            hash: 'D80E87DC1B490AD634916422453E354D8E53D2B7A5A860CDC4EA1B2249F76AA5',
            ctid: 'C0D316A20004535A',
            account: 'rDADDYfnLvVY9FBnS8zFXhwYFHPuU5q2Sk',
            destination: 'r4ERx8577eKBSAT1pLefizNPYCYfhk1op2',
            destinationTag: 114729,
            date: 847313451,
            meta: ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => '1000000000'],
            tx: [],
        )], new SettlementPolicy());

        self::assertSame('XAHAU', $view['chain']);
        self::assertSame('testnet', $view['network']);
        self::assertSame('XAH', $view['asset']);
        self::assertSame('250', $view['amountRequested']);
        self::assertNull($view['issuer']);
        self::assertSame([[
            'id' => 'D80E87DC1B490AD634916422453E354D8E53D2B7A5A860CDC4EA1B2249F76AA5',
            'sender' => 'rDADDYfnLvVY9FBnS8zFXhwYFHPuU5q2Sk',
            'amount' => ['value' => '1000', 'asset' => 'XAH', 'issuer' => null],
            'quotedAsset' => true,
            'hash' => 'D80E87DC1B490AD634916422453E354D8E53D2B7A5A860CDC4EA1B2249F76AA5',
            'ledgerIndex' => 13833890,
            'date' => '2026-11-06T20:50:51+00:00',
            'explorerUrl' => 'https://explorer.xahau-test.net/tx/D80E87DC1B490AD634916422453E354D8E53D2B7A5A860CDC4EA1B2249F76AA5',
        ]], $view['payments']);
    }

    /**
     * Each row stands for itself: two partial payments are two rows with their own amounts,
     * not the sum the payment card shows.
     */
    public function testEveryPaymentIsARowOfItsOwnInTheOrderGiven(): void
    {
        $view = ReceivedPaymentsPresenter::present($this->xrpQuote(), [
            $this->xrplPayment('HASH-NEWER', '25000000', ledgerIndex: '1001'),
            $this->xrplPayment('HASH-OLDER', '15000000', ledgerIndex: '1000'),
        ], new SettlementPolicy());

        self::assertSame(['HASH-NEWER', 'HASH-OLDER'], array_column($view['payments'], 'hash'));
        self::assertSame(['25', '15'], array_column(array_column($view['payments'], 'amount'), 'value'));
        self::assertSame([true, true], array_column($view['payments'], 'quotedAsset'));
        self::assertSame('https://testnet.xrpl.org/transactions/HASH-NEWER', $view['payments'][0]['explorerUrl']);
    }

    /**
     * PHP prints a small float with an exponent; ten drops must not read "1.0E-5".
     */
    public function testASmallNativeAmountIsAPlainDecimal(): void
    {
        $view = ReceivedPaymentsPresenter::present($this->xrpQuote(), [$this->xrplPayment('HASH-DUST', '10')], new SettlementPolicy());

        self::assertSame('0.00001', $view['payments'][0]['amount']['value']);
    }

    public function testATokenOnANativeQuoteIsListedAndMarked(): void
    {
        $view = ReceivedPaymentsPresenter::present($this->xrpQuote(), [
            $this->xrplPayment('HASH-RLUSD', ['currency' => self::RLUSD['currency'], 'value' => '12.5', 'issuer' => self::RLUSD['issuer']]),
        ], new SettlementPolicy());

        self::assertCount(1, $view['payments'], 'shown, not hidden');
        self::assertFalse($view['payments'][0]['quotedAsset']);
        self::assertSame(['value' => '12.5', 'asset' => 'RLUSD', 'issuer' => self::RLUSD['issuer']], $view['payments'][0]['amount']);
    }

    /**
     * The quoted asset of a token order is its currency code and its issuer, both.
     */
    public function testOnATokenQuoteOnlyTheQuotedCurrencyFromTheQuotedIssuerIsTheQuotedAsset(): void
    {
        $view = ReceivedPaymentsPresenter::present($this->rlusdQuote(), [
            $this->xrplPayment('HASH-RIGHT', ['currency' => self::RLUSD['currency'], 'value' => '40', 'issuer' => self::RLUSD['issuer']], ledgerIndex: '1004'),
            $this->xrplPayment('HASH-OTHER-ISSUER', ['currency' => self::RLUSD['currency'], 'value' => '40', 'issuer' => 'rSomeoneElse'], ledgerIndex: '1003'),
            $this->xrplPayment('HASH-OTHER-TOKEN', ['currency' => self::USDC['currency'], 'value' => '40', 'issuer' => self::USDC['issuer']], ledgerIndex: '1002'),
            $this->xrplPayment('HASH-NATIVE', '40000000', ledgerIndex: '1001'),
        ], new SettlementPolicy());

        self::assertSame(self::RLUSD['issuer'], $view['issuer']);
        self::assertSame('40.00', $view['amountRequested'], 'the request as the core states it');
        self::assertSame(
            ['HASH-RIGHT' => true, 'HASH-OTHER-ISSUER' => false, 'HASH-OTHER-TOKEN' => false, 'HASH-NATIVE' => false],
            array_column($view['payments'], 'quotedAsset', 'hash')
        );
        self::assertSame(['RLUSD', 'RLUSD', 'USDC', 'XRP'], array_column(array_column($view['payments'], 'amount'), 'asset'));
        self::assertSame('rSomeoneElse', $view['payments'][1]['amount']['issuer']);
        self::assertNull($view['payments'][3]['amount']['issuer']);
    }

    /**
     * An EscrowCreate carries a destination and a tag and lands in the same table; a
     * pre-2014 partial payment reports its amount as 'unavailable'. Neither is a row.
     */
    public function testWhatDeliveredNothingMeasurableIsNotARow(): void
    {
        $view = ReceivedPaymentsPresenter::present($this->xrpQuote(), [
            new XrplTransaction('testnet', '1002', 'HASH-ESCROW', 'C000000000000001', 'rSender', 'rMerchant', 114729, 0, ['TransactionResult' => 'tesSUCCESS'], []),
            new XrplTransaction('testnet', '1001', 'HASH-UNAVAILABLE', 'C000000000000002', 'rSender', 'rMerchant', 114729, 0, ['delivered_amount' => 'unavailable'], []),
            new XrplTransaction('testnet', '1000', 'HASH-MALFORMED', 'C000000000000003', 'rSender', 'rMerchant', 114729, 0, ['delivered_amount' => ['value' => '1']], []),
        ], new SettlementPolicy());

        self::assertSame([], $view['payments']);
    }

    /**
     * Stellar stores operations: two payments of one transaction share its hash, so the
     * row's identity is hash and operation. Ledger and time come from the operation.
     */
    public function testAStellarPaymentIsARowWithItsOperationAsIdentity(): void
    {
        $intent = PaymentIntent::quote(
            type: 'stellar-xlm-payment', chain: 'STELLAR', network: 'testnet', baseAsset: 'XLM', quoteCurrency: 'EUR',
            pairing: 'XLM/EUR', exchangeRate: 0.25, amountRequested: 400.0,
            destinationAccount: 'GMERCHANT', destinationTag: 114729,
        );
        // A TOID: ledger 1000 in the upper 32 bits, then the transaction's and the operation's position.
        $toid = static fn (int $operation): string => (string) ((1000 << 32) + (1 << 12) + $operation);

        $view = ReceivedPaymentsPresenter::present($intent, [
            new StellarPayment('testnet', $toid(2), 'stellarhash', 2, 'payment', 'GSENDER', 'GMERCHANT', '114729', 'USDC', self::STELLAR_USDC_ISSUER, '5.0000000', 1795000000, []),
            new StellarPayment('testnet', $toid(1), 'stellarhash', 1, 'payment', 'GSENDER', 'GMERCHANT', '114729', 'XLM', null, '400.0000000', 1795000000, []),
        ], new SettlementPolicy());

        self::assertSame(['stellarhash:2', 'stellarhash:1'], array_column($view['payments'], 'id'));
        self::assertSame(['value' => '5', 'asset' => 'USDC', 'issuer' => self::STELLAR_USDC_ISSUER], $view['payments'][0]['amount']);
        self::assertFalse($view['payments'][0]['quotedAsset']);
        self::assertSame(['value' => '400', 'asset' => 'XLM', 'issuer' => null], $view['payments'][1]['amount']);
        self::assertTrue($view['payments'][1]['quotedAsset']);
        self::assertSame(1000, $view['payments'][1]['ledgerIndex']);
        self::assertSame('2026-11-18T11:06:40+00:00', $view['payments'][1]['date']);
        self::assertSame('https://stellar.expert/explorer/testnet/tx/stellarhash', $view['payments'][1]['explorerUrl']);
    }

    /**
     * @param string|array{currency: string, value: string, issuer: string} $delivered drops, or a token amount
     */
    private function xrplPayment(string $hash, string|array $delivered, string $ledgerIndex = '1000'): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet', ledgerIndex: $ledgerIndex, hash: $hash, ctid: 'C000000000000000', account: 'rSender',
            destination: 'rMerchant', destinationTag: 114729, date: 848499942,
            meta: ['TransactionResult' => 'tesSUCCESS', 'delivered_amount' => $delivered], tx: [],
        );
    }

    private function xrpQuote(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'XRP', quoteCurrency: 'EUR',
            pairing: 'XRP/EUR', exchangeRate: 2.5, amountRequested: 40.0,
            destinationAccount: 'rMerchant', destinationTag: 114729,
        );
    }

    private function xahQuote(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xahau-xah-payment', chain: 'XAHAU', network: 'testnet', baseAsset: 'XAH', quoteCurrency: 'EUR',
            pairing: 'XAH/EUR', exchangeRate: 0.4, amountRequested: 250.0,
            destinationAccount: 'r4ERx8577eKBSAT1pLefizNPYCYfhk1op2', destinationTag: 114729,
        );
    }

    private function rlusdQuote(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment', chain: 'XRPL', network: 'testnet', baseAsset: 'RLUSD', quoteCurrency: 'USD',
            pairing: 'RLUSD/USD', exchangeRate: 1.0,
            amountRequested: ['currency' => self::RLUSD['currency'], 'value' => '40.00', 'issuer' => self::RLUSD['issuer']],
            destinationAccount: 'rMerchant', destinationTag: 114729,
        );
    }
}
