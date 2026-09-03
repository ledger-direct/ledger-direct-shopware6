<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
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
 * redirects to the LedgerDirect payment page. finalize() reads the settlement the payment page
 * found on-chain and asks the core whether it pays for the quote: paid, partially paid, or open
 * while nothing has arrived. The decision itself - tolerance for the native asset, exact
 * issuer/currency/value for a token - is the core's {@see SettlementPolicy}, so every
 * LedgerDirect plugin calls an order paid under the same conditions.
 */
// https://developer.shopware.com/docs/guides/plugins/plugins/checkout/payment/add-payment-plugin
abstract class AbstractLedgerDirectPaymentHandler extends AbstractPaymentHandler
{
    private RouterInterface $router;

    private OrderTransactionStateHandler $transactionStateHandler;

    private OrderTransactionService $transactionService;

    private SettlementPolicy $settlementPolicy;

    public function __construct(
        RouterInterface $router,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        OrderTransactionService $transactionService,
        SettlementPolicy $settlementPolicy
    ) {
        $this->router = $router;
        $this->transactionStateHandler = $orderTransactionStateHandler;
        $this->transactionService = $transactionService;
        $this->settlementPolicy = $settlementPolicy;
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

        $redirectUrl = $this->router->generate('frontend.checkout.ledger-direct.payment', [
            'orderId' => $order->getId(),
            'returnUrl' => $transaction->getReturnUrl(),
        ]);

        return new RedirectResponse($redirectUrl);
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->transactionService->getOrderTransactionById($orderTransactionId, $context);
        $intent = $orderTransaction === null ? null : $this->transactionService->readPaymentIntent($orderTransaction);

        if ($intent === null || $intent->hash === null) {
            // Nothing found on the ledger yet: the transaction stays open.
            $this->transactionStateHandler->reopen($orderTransactionId, $context);

            return;
        }

        if ($this->settlementPolicy->isSettled($intent)) {
            $this->transactionStateHandler->paid($orderTransactionId, $context);

            return;
        }

        // Less arrived than was quoted - or a token that is not the quoted one (same name, other
        // issuer), which the core does not credit at all. Either way the order is not paid.
        $this->transactionStateHandler->paidPartially($orderTransactionId, $context);
    }
}
