<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Presentation\AccentColor;
use Hardcastle\LedgerDirect\Core\Stellar\PaymentUri as StellarPaymentUri;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplAmount;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use InvalidArgumentException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * The values a payment page is built from, for every chain and asset — the
 * one place that derives them from the intent, so the storefront's Twig
 * page and the Store API's `quote` cannot drift apart: both call this.
 *
 * What differs per asset is read from the intent: its `type` names chain
 * and asset in the core's catalogue, and from there come the label, whether
 * the asset is native or issued, and the decimals. Every amount comes out of
 * AmountFormatter; nothing is rounded here or in any view.
 *
 * Not in here: what the storefront renders from Shopware itself — the
 * translated sentences, the logo markup — and the storefront's own URLs. A
 * headless front end has its own texts and builds its own poll URL; the
 * controller adds its URLs on top.
 */
final class PaymentPageView
{
    /**
     * The version of this value set as the Store API hands it out under
     * `page`. Bumped when a key is renamed or removed; added keys do not
     * bump it.
     */
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly SettlementPolicy $settlementPolicy,
        private readonly ConfigurationService $configuration,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException for an intent whose type the catalogue does not know
     */
    public function values(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        PaymentIntent $intent,
        SalesChannelContext $context,
    ): array {
        $chainAndAsset = ChainCatalog::forType($intent->type);

        if ($chainAndAsset === null) {
            throw new InvalidArgumentException('LedgerDirect: no asset in the catalogue for intent type ' . $intent->type);
        }

        $asset = $chainAndAsset[1];
        $shopName = (string) ($context->getSalesChannel()->getTranslation('name') ?? $context->getSalesChannel()->getName());
        $status = PaymentStatus::fromIntent($intent, $this->settlementPolicy);

        $amountRequested = AmountFormatter::amountRequested($intent);
        $amountPaid = AmountFormatter::amountPaid($intent);
        $shortfall = AmountFormatter::shortfall($intent, $this->settlementPolicy);
        $isToken = !$asset->native;
        $isXrpl = $intent->chain === ChainCatalog::XRPL;
        $amountDue = $status->state() === PaymentStatus::PARTIAL && $shortfall !== null ? $shortfall : $amountRequested;

        return [
            'chain' => $intent->chain,
            'mode' => strtolower($asset->code),
            'assetLabel' => $asset->code,
            // The merchant's part of the design: one accent colour, see AccentColor. The logo is the storefront's.
            'accentColor' => AccentColor::sanitize($this->configuration->getPaymentPageAccentColor()),
            'shopName' => $shopName,
            // Public browser identifiers for the wallet apps; the wallet module shows those only when set.
            'xamanApiKey' => $this->configuration->getXamanApiKey(),
            'walletConnectProjectId' => $this->configuration->getWalletConnectProjectId(),
            /*
             * The five-state payment status (INVARIANTS.md, "Payment status"),
             * rendered server-side: one block per state, the script only
             * switches them and fills in numbers from the poll.
             */
            'state' => $status->state(),
            'secondsLeft' => $status->secondsLeft,
            'hasExpiry' => $intent->expiry !== null,
            'quoteSeconds' => $this->configuration->getQuoteExpirySeconds(),
            /*
             * Every amount on the page comes out of AmountFormatter; nothing
             * is rounded in the view. The amount to send is the shortfall
             * while a partial payment is in, the request otherwise.
             */
            'amountRequestedDisplay' => $amountRequested,
            'amountPaidDisplay' => $amountPaid,
            'shortfallDisplay' => $shortfall,
            'amountDueDisplay' => $amountDue,
            'paidShare' => self::paidShare($amountPaid, $amountRequested, $status->state()),
            'wrongToken' => $this->settlementPolicy->isWrongAsset($intent),
            'exchangeRateDisplay' => AmountFormatter::rate($intent->exchangeRate),
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'total' => $orderTransaction->getAmount()->getTotalPrice(),
            'currencyCode' => $intent->quoteCurrency,
            'currencySymbol' => $order->getCurrency()?->getSymbol(),
            'network' => $intent->network,
            'explorerBase' => Explorer::transactionBase($intent->chain, $intent->network),
            'destinationAccount' => $intent->destinationAccount,
            // The order identifier as shown: the destination tag on the XRPL, the memo id on Stellar.
            'destinationTag' => $intent->destinationTag,
            // Tokens: the quoted issuer and currency, from the intent (source: the core's registry), never typed in.
            'issuer' => $isToken ? (string) $intent->amountRequested['issuer'] : null,
            // XRPL only, read by the browser-wallet module alone: the token's hex code and, for XRP, the amount in drops.
            'currencyHex' => $isXrpl && $isToken ? (string) $intent->amountRequested['currency'] : null,
            'amountDrops' => $isXrpl && !$isToken ? XrplAmount::xrpToDrops($amountDue) : null,
            // The payment request behind the QR code: the XRPL one or the SEP-0007 one, built by the core.
            'paymentUri' => $isXrpl ? PaymentUri::forIntent($intent, $amountDue) : StellarPaymentUri::forIntent($intent, $amountDue),
            'amountRequested' => $intent->amountRequested,
            'exchangeRate' => $intent->exchangeRate,
        ];
    }

    /**
     * The width of the partial-payment progress bar, in whole percent. A
     * visual only — never shown as a number, and the only arithmetic on
     * the page that is not the core's.
     */
    private static function paidShare(?string $paid, string $requested, string $state): int
    {
        if ($state !== PaymentStatus::PARTIAL || $paid === null || !is_numeric($paid) || !is_numeric($requested) || (float) $requested <= 0.0) {
            return 0;
        }

        return (int) max(0, min(100, round(((float) $paid / (float) $requested) * 100)));
    }
}
