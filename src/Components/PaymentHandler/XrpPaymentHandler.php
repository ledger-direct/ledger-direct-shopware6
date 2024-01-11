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

class XrpPaymentHandler implements AsynchronousPaymentHandlerInterface
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

    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        $orderTransaction = $transaction->getOrderTransaction();
        $customFields = $orderTransaction->getCustomFields();

        if (isset($customFields['xrpl']['hash']) && isset($customFields['xrpl']['ctid'])) {
            // Payment is settled, let's check wether the paid amount is enough
            $requestedXrpAmount = (float) $customFields['xrpl']['amount_requested'];
            $paidXrpAmount = (float) $customFields['xrpl']['amount_paid'];
            $slippage = 0.0015; // TODO: Make this configurable
            $slipped = 1.0 - $paidXrpAmount / $requestedXrpAmount;
            if($slipped < $slippage) {
                // Payment completed, set transaction status to "paid"
                $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                return;
            }
            // Payment partially completed, mark as such
            $this->transactionStateHandler->payPartially($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
        } else {
            // Payment not completed, set transaction status to "open"
            $this->transactionStateHandler->reopen($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
        }
    }
}