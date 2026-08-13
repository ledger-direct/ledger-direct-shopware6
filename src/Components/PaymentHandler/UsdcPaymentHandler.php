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

class UsdcPaymentHandler extends AbstractPaymentHandler
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
            $requestedTokenAmount = $customFields['ledger_direct']['amount_requested'];
            $paidTokenAmount = $customFields['ledger_direct']['delivered_amount'];
            if ($requestedTokenAmount === $paidTokenAmount) {
                $this->transactionStateHandler->paid($orderTransactionId, $context);
                return;
            } else {
                $this->transactionStateHandler->paidPartially($orderTransactionId, $context);
            }
        } else {
            $this->transactionStateHandler->reopen($orderTransactionId, $context);
        }
    }
}
