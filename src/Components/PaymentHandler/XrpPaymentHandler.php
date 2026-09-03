<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

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
 * Payment handler for XRP payments on the XRP Ledger.
 *
 * pay() prepares the order transaction (destination tag, requested amount, exchange rate)
 * and redirects to the LedgerDirect payment page; finalize() marks the transaction as
 * paid / partially paid (within a small slippage tolerance) / open, based on the
 * settlement found on-chain.
 */
// https://developer.shopware.com/docs/guides/plugins/plugins/checkout/payment/add-payment-plugin
class XrpPaymentHandler extends AbstractPaymentHandler
{
    private RouterInterface $router;

    private OrderTransactionStateHandler $transactionStateHandler;

    private OrderTransactionService $transactionService;

    public function __construct(
        RouterInterface              $router,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        OrderTransactionService      $transactionService
    )
    {
        $this->router = $router;
        $this->transactionStateHandler = $orderTransactionStateHandler;
        $this->transactionService = $transactionService;
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
            'returnUrl' => $transaction->getReturnUrl()
        ]);

        return new RedirectResponse($redirectUrl);
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->transactionService->getOrderTransactionById($orderTransactionId, $context);
        $customFields = $orderTransaction?->getCustomFields() ?? [];

        if (isset($customFields['ledger_direct']['hash']) && isset($customFields['ledger_direct']['ctid'])) {
            // Payment is settled, let's check wether the paid amount is enough
            $requestedXrpAmount = (float) $customFields['ledger_direct']['amount_requested'];
            $paidXrpAmount = (float) $customFields['ledger_direct']['amount_paid'];
            $slippage = 0.0015; // TODO: Make this configurable
            $slipped = 1.0 - $paidXrpAmount / $requestedXrpAmount;
            if($slipped < $slippage) {
                // Payment completed, set transaction status to "paid"
                $this->transactionStateHandler->paid($orderTransactionId, $context);
                return;
            }
            // Payment partially completed, mark as such
            $this->transactionStateHandler->paidPartially($orderTransactionId, $context);
        } else {
            // Payment not completed, set transaction status to "open"
            $this->transactionStateHandler->reopen($orderTransactionId, $context);
        }
    }
}
