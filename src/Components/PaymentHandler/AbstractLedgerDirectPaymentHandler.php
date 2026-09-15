<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * What the XRP, RLUSD and USDC payment handlers have in common - which is everything: which
 * asset an order is quoted in is decided by the payment method, not by the handler.
 *
 * pay() prepares the order transaction (destination tag, requested amount, exchange rate) and
 * redirects to the LedgerDirect payment page. finalize() runs when the customer comes back over
 * the returnUrl; it reads the settlement the payment page found on-chain and applies the
 * matching state — paid, partially paid, or reopened while nothing has arrived. The status
 * endpoint usually got there first (see {@see PaymentStateService}), so finalize() is a
 * formality that must tolerate a state already set; the decision itself is the core's
 * SettlementPolicy, so every LedgerDirect plugin calls an order paid under the same conditions.
 */
// https://developer.shopware.com/docs/guides/plugins/plugins/checkout/payment/add-payment-plugin
abstract class AbstractLedgerDirectPaymentHandler extends AbstractPaymentHandler
{
    private RouterInterface $router;

    private OrderTransactionService $transactionService;

    private PaymentStateService $paymentState;

    public function __construct(
        RouterInterface $router,
        OrderTransactionService $transactionService,
        PaymentStateService $paymentState
    ) {
        $this->router = $router;
        $this->transactionService = $transactionService;
        $this->paymentState = $paymentState;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // No refunds or recurring payments supported.
        return false;
    }

    /**
     * @throws \Exception
     */
    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $orderTransaction = $this->transactionService->getOrderTransactionById($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction?->getOrder();

        if ($orderTransaction === null || $order === null) {
            throw new \RuntimeException('LedgerDirect: order transaction not found for ' . $transaction->getOrderTransactionId());
        }

        $this->transactionService->prepareOrderTransactionForXrpl($order, $orderTransaction, $context);

        // The deepLinkCode is the order secret the payment page and the status
        // endpoint accept instead of a login — a guest has no other way in.
        $redirectUrl = $this->router->generate('frontend.checkout.ledger-direct.payment', [
            'orderId' => $order->getId(),
            'returnUrl' => $transaction->getReturnUrl(),
            'deepLinkCode' => $order->getDeepLinkCode(),
        ]);

        return new RedirectResponse($redirectUrl);
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $orderTransaction = $this->transactionService->getOrderTransactionById($transaction->getOrderTransactionId(), $context);

        if ($orderTransaction === null) {
            throw new \RuntimeException('LedgerDirect: order transaction not found for ' . $transaction->getOrderTransactionId());
        }

        $intent = $this->transactionService->readPaymentIntent($orderTransaction);

        if ($intent === null || $intent->hash === null) {
            // Nothing found on the ledger yet: back to open, if the customer was away.
            $this->paymentState->reopenIfAway($orderTransaction, $context);

            return;
        }

        $this->paymentState->applyState($orderTransaction, $intent, $context);
    }
}
