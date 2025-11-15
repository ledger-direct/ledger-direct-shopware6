<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Components\PaymentHandler;

use Exception;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

class UsdcPaymentHandler implements AsynchronousPaymentHandlerInterface
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

    /**
     * @throws Exception
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

        if (isset($customFields['ledger_direct']['hash']) && isset($customFields['ledger_direct']['ctid'])) {
            $requestedTokenAmount = $customFields['ledger_direct']['amount_requested'];
            $paidTokenAmount = $customFields['ledger_direct']['delivered_amount'];
            if ($requestedTokenAmount === $paidTokenAmount) {
                $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                return;
            } else {
                $this->transactionStateHandler->payPartially($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
            }
        } else {
            $this->transactionStateHandler->reopen($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
        }
    }
}
