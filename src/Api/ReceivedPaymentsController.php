<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

use Hardcastle\LedgerDirect\Chain\ChainRegistry;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Presentation\ReceivedPaymentsPresenter;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Hardcastle\LedgerDirect\Service\PaymentStateService;
use InvalidArgumentException;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ledger payments an order received, for the administration's order
 * detail.
 *
 * Every order transaction with a LedgerDirect payment record is listed, a
 * cancelled or failed one too, and not only the one Shopware shows as
 * current: each has its own tag, and a customer can pay an attempt the shop
 * has given up on. An order without such a transaction answers an empty
 * list, so the administration can ask for any order.
 *
 * Read-only, and from the local transaction table alone: like the payment
 * card's route (PaymentInfoController) this starts no ledger sync. Whoever
 * may read the order may read this (`order:read`). 404 for an unknown order.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReceivedPaymentsController extends AbstractController
{
    public function __construct(
        private readonly OrderTransactionService $orderTransactionService,
        private readonly SettlementPolicy $settlementPolicy,
    ) {
    }

    #[Route(
        path: '/api/_action/ledger-direct/order/{orderId}/payments',
        name: 'api.action.ledger-direct.order.payments',
        defaults: ['_acl' => ['order:read']],
        methods: ['GET']
    )]
    public function payments(string $orderId, Context $context): JsonResponse
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context);

        if ($order === null) {
            return new JsonResponse(['error' => 'order_not_found'], Response::HTTP_NOT_FOUND);
        }

        $transactions = [];

        foreach ($order->getTransactions() ?? [] as $orderTransaction) {
            $entry = [
                'orderTransactionId' => $orderTransaction->getId(),
                'state' => PaymentStateService::stateOf($orderTransaction),
                'stateName' => $orderTransaction->getStateMachineState()?->getTranslation('name'),
            ];

            try {
                $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);
            } catch (InvalidArgumentException) {
                // Named, not skipped: money may have arrived on a record the plugin cannot read,
                // and one such record must not take the other transactions' rows with it.
                $transactions[] = $entry + ['unreadable' => true, 'payments' => []];

                continue;
            }

            if ($intent === null) {
                // Another payment method's transaction, or one of ours that was never quoted.
                continue;
            }

            if ($intent->chain === ChainRegistry::INTERLEDGER) {
                // Open Payments keeps no record of single payments: the incoming payment is the
                // order's one record, and the LedgerDirect card shows what arrived on it.
                continue;
            }

            $transactions[] = $entry + ['unreadable' => false] + ReceivedPaymentsPresenter::present(
                $intent,
                $this->orderTransactionService->findReceivedPayments($intent),
                $this->settlementPolicy
            );
        }

        return new JsonResponse(['transactions' => $transactions]);
    }
}
