<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Installer;

use Hardcastle\LedgerDirect\Components\PaymentHandler\RlusdPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\StellarPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\UsdcPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\XrplPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\XrpPaymentHandler;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use PHPUnit\Framework\TestCase;

/**
 * The payment methods are derived from the core's ChainCatalog; the plugin
 * only maps them onto Shopware's objects. The completeness test is the
 * mechanism that keeps the broad surface together: a chain the core adds
 * turns it red until this plugin has a handler for it.
 */
class PaymentMethodInstallerTest extends TestCase
{
    /**
     * Every chain in the catalogue is either handled here or named below as
     * pending. A third entry in the catalogue fails this test on purpose.
     */
    public function testEveryCatalogueChainIsHandledOrExplicitlyPending(): void
    {
        $pending = []; // Every chain the core 0.10 catalogue lists is handled: XRPL since 1.0, Stellar since 1.7.0.

        foreach (ChainCatalog::chains() as $chain) {
            $handled = isset(PaymentMethodInstaller::HANDLER_BY_CHAIN[$chain->chain]);
            $this->assertTrue(
                $handled || in_array($chain->chain, $pending, true),
                "The core's catalogue lists chain {$chain->chain}, which this plugin neither handles nor lists as pending"
            );
        }

        foreach ($pending as $chain) {
            $this->assertArrayNotHasKey($chain, PaymentMethodInstaller::HANDLER_BY_CHAIN, "{$chain} is handled; drop it from the pending list");
        }
    }

    /**
     * For every asset of a handled chain there is a payment method, and the
     * method finds its chain and asset again — what the controller, the
     * checkout filter and the scheduled task rely on.
     */
    public function testEveryAssetOfAHandledChainHasAMethodThatMapsBack(): void
    {
        foreach (PaymentMethodInstaller::supportedChains() as $chain) {
            foreach ($chain->assets as $asset) {
                $id = PaymentMethodInstaller::paymentMethodIdFor($asset->type);
                $this->assertContains($id, PaymentMethodInstaller::paymentMethodIds(), $asset->type);

                [$foundChain, $foundAsset] = PaymentMethodInstaller::chainAndAssetFor($id);
                $this->assertSame($chain->chain, $foundChain->chain);
                $this->assertSame($asset->code, $foundAsset->code);
            }
        }

        $this->assertNull(PaymentMethodInstaller::chainAndAssetFor('0190c1d8a0e24c2b9c1e1a7b1d2e3f41'), 'not one of ours');
        $this->assertNull(PaymentMethodInstaller::chainAndAssetFor(null));
    }

    /**
     * The three XRPL methods keep the ids, technical names and handler
     * identifiers they have had since the first release: orders reference
     * the ids, and the administration's order card and the storefront's
     * icon mapping know the handlers.
     */
    public function testTheThreeXrplMethodsKeepTheirIdentity(): void
    {
        $byId = array_column(PaymentMethodInstaller::paymentMethods(), null, 'id');

        $this->assertSame(['ledgerdirect_xrpl_xrp', XrpPaymentHandler::class], [$byId[PaymentMethodInstaller::XRP_PAYMENT_ID]['technicalName'], $byId[PaymentMethodInstaller::XRP_PAYMENT_ID]['handlerIdentifier']]);
        $this->assertSame(['ledgerdirect_xrpl_rlusd', RlusdPaymentHandler::class], [$byId[PaymentMethodInstaller::RLUSD_PAYMENT_ID]['technicalName'], $byId[PaymentMethodInstaller::RLUSD_PAYMENT_ID]['handlerIdentifier']]);
        $this->assertSame(['ledgerdirect_xrpl_usdc', UsdcPaymentHandler::class], [$byId[PaymentMethodInstaller::USDC_PAYMENT_ID]['technicalName'], $byId[PaymentMethodInstaller::USDC_PAYMENT_ID]['handlerIdentifier']]);
        $this->assertSame('XRP on XRPL', $byId[PaymentMethodInstaller::XRP_PAYMENT_ID]['translations']['en-GB']['name']);
        $this->assertCount(6, $byId, 'three XRPL methods, three Stellar ones');

        foreach ([XrpPaymentHandler::class, RlusdPaymentHandler::class, UsdcPaymentHandler::class] as $legacy) {
            $this->assertContains(XrplPaymentHandler::class, (array) class_parents($legacy), "{$legacy} is the chain handler under its old name");
        }
    }

    /**
     * The Stellar methods: id from the type, the chain handler, a technical
     * name and a name built from the catalogue - no constant per asset.
     */
    public function testTheStellarMethodsAreDerivedFromTheCatalogue(): void
    {
        $byId = array_column(PaymentMethodInstaller::paymentMethods(), null, 'id');
        $xlm = $byId[md5('stellar-xlm-payment')];

        $this->assertSame(StellarPaymentHandler::class, $xlm['handlerIdentifier']);
        $this->assertSame('ledgerdirect_stellar_xlm', $xlm['technicalName']);
        $this->assertSame('XLM on Stellar', $xlm['translations']['en-GB']['name']);
        $this->assertSame('XLM (Stellar)', $xlm['translations']['de-DE']['name']);
        $this->assertSame('ledgerdirect_stellar_eurc', $byId[md5('stellar-eurc-payment')]['technicalName']);

        [$chain, $asset] = PaymentMethodInstaller::chainAndAssetFor(md5('stellar-usdc-payment'));
        $this->assertSame(['STELLAR', 'USDC'], [$chain->chain, $asset->code]);
    }

    /**
     * A method that is not one of the first three derives its id from the
     * intent type, the same on every installation.
     */
    public function testALaterMethodDerivesItsIdFromTheType(): void
    {
        $this->assertSame(md5('stellar-usdc-payment'), PaymentMethodInstaller::paymentMethodIdFor('stellar-usdc-payment'));
        $this->assertSame(PaymentMethodInstaller::XRP_PAYMENT_ID, PaymentMethodInstaller::paymentMethodIdFor('xrp-payment'));
    }
}
