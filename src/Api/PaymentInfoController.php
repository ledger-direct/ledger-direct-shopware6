<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Api;

use Hardcastle\LedgerDirect\Components\PaymentHandler\RlusdPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\UsdcPaymentHandler;
use Hardcastle\LedgerDirect\Components\PaymentHandler\XrpPaymentHandler;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Presentation\PaymentInfoPresenter;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use InvalidArgumentException;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The payment card's data for the administration's order detail.
 *
 * Read-only: the verdict and the display strings for one order transaction,
 * computed on the server (PaymentInfoPresenter). Whoever may read the order
 * may read this (`order:read`). 404 for a transaction that is not one of
 * ours or has no payment record, 422 for a record the plugin cannot read.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class PaymentInfoController extends AbstractController
{
    private const HANDLERS = [XrpPaymentHandler::class, RlusdPaymentHandler::class, UsdcPaymentHandler::class];

    public function __construct(
        private readonly OrderTransactionService $orderTransactionService,
        private readonly SettlementPolicy $settlementPolicy,
    ) {
    }

    #[Route(
        path: '/api/_action/ledger-direct/payment-info/{orderTransactionId}',
        name: 'api.action.ledger-direct.payment-info',
        defaults: ['_acl' => ['order:read']],
        methods: ['GET']
    )]
    public function info(string $orderTransactionId, Context $context): JsonResponse
    {
        $orderTransaction = $this->orderTransactionService->getOrderTransactionById($orderTransactionId, $context);

        if ($orderTransaction === null || !in_array($orderTransaction->getPaymentMethod()?->getHandlerIdentifier(), self::HANDLERS, true)) {
            return new JsonResponse(['error' => 'not_a_ledger_direct_transaction'], Response::HTTP_NOT_FOUND);
        }

        try {
            $intent = $this->orderTransactionService->readPaymentIntent($orderTransaction);
        } catch (InvalidArgumentException) {
            return new JsonResponse(['error' => 'payment_intent_unreadable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($intent === null) {
            return new JsonResponse(['error' => 'no_payment_intent'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(PaymentInfoPresenter::present($intent, $this->settlementPolicy));
    }
}
