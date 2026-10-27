<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Storefront\Controller;

use Hardcastle\LedgerDirect\Core\AssetDescriptor;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplAmount;
use Hardcastle\LedgerDirect\Installer\PaymentMethodInstaller;
use Hardcastle\LedgerDirect\Core\Presentation\AccentColor;
use Hardcastle\LedgerDirect\Presentation\AmountFormatter;
use Hardcastle\LedgerDirect\Presentation\PageLogo;
use Hardcastle\LedgerDirect\Core\Payment\PaymentUri;
use Hardcastle\LedgerDirect\Core\Stellar\PaymentUri as StellarPaymentUri;
use Hardcastle\LedgerDirect\Presentation\Explorer;
use Hardcastle\LedgerDirect\SalesChannel\PaymentRoute;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Service\OrderAccessGuard;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentRedirect;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route(defaults: ['_routeScope' => ['storefront']])]
class XrplPaymentController extends StorefrontController
{
    private OrderTransactionService $orderTransactionService;

    private PaymentRoute $paymentRoute;

    private SettlementPolicy $settlementPolicy;

    private OrderAccessGuard $orderAccessGuard;

    private PaymentStateService $paymentState;

    private PaymentRedirect $paymentRedirect;

    private ConfigurationService $configuration;

    private PageLogo $pageLogo;

    public function __construct(
        OrderTransactionService $orderTransactionService,
        PaymentRoute $paymentRoute,
        SettlementPolicy $settlementPolicy,
        OrderAccessGuard $orderAccessGuard,
        PaymentStateService $paymentState,
        PaymentRedirect $paymentRedirect,
        ConfigurationService $configuration,
        PageLogo $pageLogo
    ) {
        $this->orderTransactionService = $orderTransactionService;
        $this->paymentRoute = $paymentRoute;
        $this->settlementPolicy = $settlementPolicy;
        $this->orderAccessGuard = $orderAccessGuard;
        $this->paymentState = $paymentState;
        $this->paymentRedirect = $paymentRedirect;
        $this->configuration = $configuration;
        $this->pageLogo = $pageLogo;
    }

    /**
     * No _loginRequired: the order's deepLinkCode (or the session customer
     * the order belongs to) authorises the request, see OrderAccessGuard.
     * With _loginRequired a guest — who has just placed the order — was sent
     * to a login page instead of the payment instructions.
     */
    #[Route(path: '/ledger-direct/payment/{orderId}', name: 'frontend.checkout.ledger-direct.payment', methods: ['GET', 'POST'], options: ['seo' => 'false'])]
    public function payment(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        $order = $this->orderAccessGuard->authorisedOrder($orderId, $request, $context);

        if (!$order) {
            // Same answer for "no such order" and "not yours": neither is told apart.
            return $this->redirectToRoute('frontend.account.order.page');
        }

        $orderTransaction = $order->getTransactions()->first();
        if (!$orderTransaction) {
            return $this->redirectToRoute('frontend.account.order.page');
        }

        $returnUrl = (string) $request->get('returnUrl');

        /*
         * Synced on render so the first page after the checkout shows the
         * current state; throttled so a reload costs no node request. A hit
         * moves the transaction's state right here, as the status endpoint
         * does.
         *
         * Something arriving on the tag is not the same as the order being
         * paid. A payment in the right asset but from the wrong issuer, or
         * one that falls short, is recorded on the intent but does not
         * settle it — sending the customer back to the shop at that point
         * leaves them with a partially paid order and no explanation, so
         * they stay here and the page tells them what is missing. Only a
         * transaction that is no longer open — settled, or closed by the
         * merchant — leaves the page.
         */
        $orderTransaction = $this->paymentState->syncAndApply($orderTransaction, $context->getContext(), throttled: true);

        if (!$this->paymentState->isOpen($orderTransaction)) {
            return new RedirectResponse($this->paymentRedirect->target($order, $returnUrl));
        }

        if (PaymentMethodInstaller::chainAndAssetFor($orderTransaction->getPaymentMethodId()) === null) {
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->renderPaymentPage($order, $orderTransaction, $returnUrl, $context);
    }

    #[Route(path: '/ledger-direct/payment/check/{orderId}', name: 'frontend.checkout.ledger-direct.check-payment', methods: ['GET', 'POST'], defaults: ['XmlHttpRequest' => true])]
    public function checkPayment(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        return $this->paymentRoute->check($orderId, $request, $context);
    }

    /**
     * A new quote for an expired one — the button under the "quote expired"
     * notice. Only while nothing has arrived: a payment on the tag, short or
     * in the wrong asset, is matched against the quote it was made for, and
     * a refresh would re-quote an order that is already partly paid. The
     * destination account and tag stay the same either way
     * (see OrderTransactionService::prepareOrderTransaction()).
     */
    #[Route(path: '/ledger-direct/payment/refresh/{orderId}', name: 'frontend.checkout.ledger-direct.refresh-quote', methods: ['POST'], options: ['seo' => 'false'])]
    public function refreshQuote(SalesChannelContext $context, string $orderId, Request $request): Response
    {
        $order = $this->orderAccessGuard->authorisedOrder($orderId, $request, $context);

        if (!$order) {
            return $this->redirectToRoute('frontend.account.order.page');
        }

        $backToPaymentPage = $this->redirectToRoute('frontend.checkout.ledger-direct.payment', array_filter([
            'orderId' => $order->getId(),
            'deepLinkCode' => (string) $order->getDeepLinkCode(),
            'returnUrl' => (string) $request->get('returnUrl'),
        ]));

        $orderTransaction = $order->getTransactions()->first();
        $intent = $orderTransaction === null ? null : $this->orderTransactionService->readPaymentIntent($orderTransaction);

        if ($intent === null || !$this->paymentState->isOpen($orderTransaction)) {
            return $backToPaymentPage;
        }

        if (PaymentStatus::fromIntent($intent, $this->settlementPolicy)->state() !== PaymentStatus::EXPIRED) {
            return $backToPaymentPage;
        }

        $this->orderTransactionService->prepareOrderTransaction($order, $orderTransaction, $context->getContext());

        return $backToPaymentPage;
    }

    /**
     * The one payment page, for every chain and asset: what differs is read
     * from the intent — its `type` names chain and asset in the core's
     * catalogue (ChainCatalog::forType()), and from there come the label,
     * whether the asset is native or issued, and the decimals.
     */
    private function renderPaymentPage(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        string $returnUrl,
        SalesChannelContext $context,
    ): Response
    {
        $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);
        $chainAndAsset = $intent === null ? null : ChainCatalog::forType($intent->type);

        if ($intent === null || $chainAndAsset === null) {
            $this->addFlash('danger', 'This order cannot be paid with LedgerDirect. Please contact support.');
            return $this->redirectToRoute('frontend.checkout.cart.page');
        }

        return $this->renderStorefront(
            '@Storefront/storefront/ledger-direct/payment.html.twig',
            $this->paymentPageParameters($order, $orderTransaction, $intent, $chainAndAsset[1], $returnUrl, $context)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPageParameters(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        PaymentIntent $intent,
        AssetDescriptor $asset,
        string $returnUrl,
        SalesChannelContext $context,
    ): array {
        $shopName = (string) ($context->getSalesChannel()->getTranslation('name') ?? $context->getSalesChannel()->getName());
        $status = PaymentStatus::fromIntent($intent, $this->settlementPolicy);
        $deepLinkCode = (string) $order->getDeepLinkCode();

        $routeParameters = array_filter([
            'orderId' => $order->getId(),
            'deepLinkCode' => $deepLinkCode,
            'returnUrl' => $returnUrl,
        ]);

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
            // The merchant's part of the design: one accent colour and the logo, see AccentColor and PageLogo.
            'accentColor' => AccentColor::sanitize($this->configuration->getPaymentPageAccentColor()),
            'logo' => $this->pageLogo->forShop($shopName, $context->getContext()),
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
            'currencySymbol' => $order->getCurrency()->getSymbol(),
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
            'returnUrl' => $returnUrl,
            'deepLinkCode' => $deepLinkCode,
            'pageUrl' => $this->generateUrl('frontend.checkout.ledger-direct.payment', ['orderId' => $order->getId()]),
            'pollUrl' => $this->generateUrl('frontend.checkout.ledger-direct.check-payment', $routeParameters),
            'refreshUrl' => $this->generateUrl('frontend.checkout.ledger-direct.refresh-quote', ['orderId' => $order->getId()]),
        ];
    }

    /**
     * The width of the partial-payment progress bar, in whole percent. A
     * visual only — never shown as a number, and the only arithmetic on
     * this page that is not the core's.
     */
    private static function paidShare(?string $paid, string $requested, string $state): int
    {
        if ($state !== PaymentStatus::PARTIAL || $paid === null || !is_numeric($paid) || !is_numeric($requested) || (float) $requested <= 0.0) {
            return 0;
        }

        return (int) max(0, min(100, round(((float) $paid / (float) $requested) * 100)));
    }
}
