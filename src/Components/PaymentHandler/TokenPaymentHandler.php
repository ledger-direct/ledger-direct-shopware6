<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

class TokenPaymentHandler implements AsynchronousPaymentHandlerInterface
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

    // https://developer.shopware.com/docs/guides/plugins/plugins/checkout/payment/add-payment-plugin

    /**
     *
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $this->transactionService->prepareOrderTransactionForXrpl(
            $transaction->getOrder(),
            $transaction->getOrderTransaction(),
            $salesChannelContext->getContext()
        );

        $redirectUrl = $this->router->generate('frontend.checkout.ledger-direct.payment', [
            'orderId' => $transaction->getOrder()->getId(),
            'returnUrl' => $transaction->getReturnUrl()
        ]);

        return new RedirectResponse($redirectUrl);
    }

    /**
     *
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        $orderTransaction = $transaction->getOrderTransaction();
        $customFields = $orderTransaction->getCustomFields();

        if (isset($customFields['leger_direct']['hash']) && isset($customFields['ledger_direct']['ctid'])) {
            // Payment is settled, let's check wether the paid amount is enough
            $requestedTokenAmount = (float) $customFields['ledger_direct']['value'];
            $paidTokenAmount = (float) $customFields['ledger_direct']['delivered_amount'];
            if ($requestedTokenAmount === $paidTokenAmount) {
                // Payment completed, set transaction status to "paid"
                $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                return;
            } else {
                // Payment partially completed, mark as such
                $this->transactionStateHandler->payPartially($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
            }
        } else {
            // Payment not completed, set transaction status to "open"
            $this->transactionStateHandler->reopen($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
        }
    }
}