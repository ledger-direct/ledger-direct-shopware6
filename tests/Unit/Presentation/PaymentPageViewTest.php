<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Presentation\PaymentPageView;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * The payment page's values, derived once from the intent for the Twig page
 * and the Store API alike. The markup contract of @ledger-direct/payment-ui
 * (src/README.md there) is what a front end builds from them, so every
 * value the contract needs has to be here, for a native asset and a token.
 */
class PaymentPageViewTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const USDC = ['currency' => '5553444300000000000000000000000000000000', 'issuer' => 'rHuGNhqTG32mfmAvWA8hUyWRLV3tCSwKQt'];

    /**
     * What the contract's attributes and blocks are rendered from. A key
     * removed here is a key the Twig page and every headless page lose.
     */
    private const CONTRACT_KEYS = [
        'chain', 'mode', 'assetLabel', 'accentColor', 'shopName', 'xamanApiKey', 'walletConnectProjectId',
        'state', 'secondsLeft', 'hasExpiry', 'quoteSeconds',
        'amountRequestedDisplay', 'amountPaidDisplay', 'shortfallDisplay', 'amountDueDisplay', 'paidShare', 'wrongToken', 'exchangeRateDisplay',
        'orderId', 'orderNumber', 'total', 'currencyCode', 'currencySymbol', 'network', 'explorerBase',
        'destinationAccount', 'destinationTag', 'issuer', 'currencyHex', 'amountDrops', 'paymentUri',
        'amountRequested', 'exchangeRate',
    ];

    public function testAnXrpPageHasEveryValueTheContractNeeds(): void
    {
        $values = $this->view()->values($this->order(), $this->transaction(), $this->xrp(), $this->salesChannel('Beispielshop'));

        $this->assertSame(self::CONTRACT_KEYS, array_keys($values), 'the value set, in its order');
        $this->assertSame('XRPL', $values['chain']);
        $this->assertSame('xrp', $values['mode']);
        $this->assertSame('XRP', $values['assetLabel']);
        $this->assertSame('waiting', $values['state']);
        $this->assertTrue($values['hasExpiry']);
        $this->assertSame('40', $values['amountRequestedDisplay']);
        $this->assertSame('40', $values['amountDueDisplay']);
        $this->assertSame('40000000', $values['amountDrops'], 'the amount in drops for the wallet module');
        $this->assertNull($values['issuer']);
        $this->assertNull($values['currencyHex']);
        $this->assertStringContainsString('rMerchant', $values['paymentUri']);
        $this->assertStringContainsString('114729', $values['paymentUri']);
        $this->assertStringEndsWith('&network=TESTNET', $values['paymentUri'], 'Xaman pays on the named network, not on the one the wallet has open');
        $this->assertSame(114729, $values['destinationTag']);
        $this->assertSame('Beispielshop', $values['shopName']);
        $this->assertSame('#336699', $values['accentColor']);
        $this->assertSame('xaman-key', $values['xamanApiKey']);
        $this->assertSame(300, $values['quoteSeconds']);
        $this->assertSame('10010', $values['orderNumber']);
        $this->assertSame(10.0, $values['total']);
        $this->assertSame('€', $values['currencySymbol']);
        $this->assertNotSame('', $values['explorerBase']);
    }

    /**
     * Xahau speaks the XRPL protocol, so its page carries the XRPL payment
     * request — with the network named in it, which is what makes Xaman offer
     * the switch to Xahau. The values only the browser-wallet module reads
     * stay empty: that module serves the XRP Ledger alone.
     */
    public function testAXahauPageCarriesTheXrplRequestWithItsNetworkAndNoWalletValues(): void
    {
        $intent = PaymentIntent::quote(
            type: 'xahau-xah-payment',
            chain: 'XAHAU',
            network: 'testnet',
            baseAsset: 'XAH',
            quoteCurrency: 'EUR',
            pairing: 'XAH/EUR',
            exchangeRate: 0.0091,
            amountRequested: 1.5,
            destinationAccount: 'r4ERx8577eKBSAT1pLefizNPYCYfhk1op2',
            destinationTag: 1050508800,
            expiry: time() + 300,
        );

        $values = $this->view()->values($this->order(), $this->transaction(), $intent, $this->salesChannel('Shop'));

        $this->assertSame(self::CONTRACT_KEYS, array_keys($values));
        $this->assertSame('XAHAU', $values['chain']);
        $this->assertSame('xah', $values['mode']);
        $this->assertSame('XAH', $values['assetLabel']);
        $this->assertSame('1.5', $values['amountDueDisplay']);
        $this->assertSame(
            'https://xrplf.org//send?to=r4ERx8577eKBSAT1pLefizNPYCYfhk1op2&dt=1050508800&amount=1.5&network=XAHAUTESTNET',
            $values['paymentUri'],
        );
        $this->assertSame('https://explorer.xahau-test.net/tx/', $values['explorerBase']);
        $this->assertSame(1050508800, $values['destinationTag']);
        $this->assertNull($values['amountDrops'], 'read by the XRPL wallet module only');
        $this->assertNull($values['currencyHex']);
        $this->assertNull($values['issuer']);
    }

    public function testATokenPageNamesIssuerAndCurrencyAndHasNoDrops(): void
    {
        $values = $this->view()->values($this->order(), $this->transaction(), $this->usdc(), $this->salesChannel('Shop'));

        $this->assertSame('usdc', $values['mode']);
        $this->assertSame('USDC', $values['assetLabel']);
        $this->assertSame(self::USDC['issuer'], $values['issuer']);
        $this->assertSame(self::USDC['currency'], $values['currencyHex']);
        $this->assertNull($values['amountDrops']);
        $this->assertSame('40.00', $values['amountRequestedDisplay']);
        $this->assertFalse($values['wrongToken']);
    }

    /**
     * A partial payment: the amount to send becomes the shortfall, in the
     * display, the drops and the QR code alike, and the bar knows its share.
     */
    public function testAPartialPaymentTurnsTheAmountDueIntoTheShortfall(): void
    {
        $partial = $this->xrp()->withFulfillment('HASH', 30.0, 'CTID');

        $values = $this->view()->values($this->order(), $this->transaction(), $partial, $this->salesChannel('Shop'));

        $this->assertSame('partial', $values['state']);
        $this->assertSame('30', $values['amountPaidDisplay']);
        $this->assertSame('10', $values['shortfallDisplay']);
        $this->assertSame('10', $values['amountDueDisplay']);
        $this->assertSame('10000000', $values['amountDrops']);
        $this->assertStringContainsString('amount=10', $values['paymentUri']);
        $this->assertSame(75, $values['paidShare']);
    }

    public function testAnIntentOfAnUnknownTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->view()->values($this->order(), $this->transaction(), $this->xrp(type: 'doge-payment'), $this->salesChannel('Shop'));
    }

    private function view(): PaymentPageView
    {
        return new PaymentPageView(new SettlementPolicy(), ConfigurationServiceMock::createInstance([
            'LedgerDirect.config.paymentPageAccentColor' => '#336699',
            'LedgerDirect.config.xamanApiKey' => 'xaman-key',
            'LedgerDirect.config.xrplQuoteExpiry' => 300,
        ]));
    }

    private function order(): OrderEntity
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setSymbol('€');

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setOrderNumber('10010');
        $order->setCurrency($currency);

        return $order;
    }

    private function transaction(): OrderTransactionEntity
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('order-transaction-id');
        $orderTransaction->setAmount(new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        return $orderTransaction;
    }

    private function salesChannel(string $name): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-id');
        $salesChannel->setName($name);

        /** @var SalesChannelContext&Mockery\MockInterface $context */
        $context = Mockery::mock(SalesChannelContext::class);
        $context->shouldReceive('getSalesChannel')->andReturn($salesChannel);

        return $context;
    }

    private function xrp(string $type = 'xrp-payment'): PaymentIntent
    {
        return PaymentIntent::quote(
            type: $type,
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 2.5,
            amountRequested: 40.0,
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
            expiry: time() + 300,
        );
    }

    private function usdc(): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'usdc-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'USDC',
            quoteCurrency: 'USD',
            pairing: 'USDC/USD',
            exchangeRate: 1.0,
            amountRequested: self::USDC + ['value' => '40.00'],
            destinationAccount: 'rMerchant',
            destinationTag: 114729,
            expiry: time() + 300,
        );
    }
}
