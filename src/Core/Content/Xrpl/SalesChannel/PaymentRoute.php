<?php declare(strict_types=1);

namespace LedgerDirect\Core\Content\Xrpl\SalesChannel;

use OpenApi\Annotations as OA;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\Annotation\Entity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use LedgerDirect\Service\OrderTransactionService;

/**
 * @Route(defaults={"_routeScope"={"store-api"}})
 */
class PaymentRoute
{
    private OrderTransactionService $orderTransactionService;

    /**
     * @param OrderTransactionService $orderTransactionService
     */
    public function __construct(OrderTransactionService $orderTransactionService)
    {
        $this->orderTransactionService = $orderTransactionService;
    }

    /**
     * @Route("/store-api/ledger-direct/payment/check/{orderId}", name="store-api.ledger-direct.payment.check", methods={"GET", "POST"})
     */
    public function check(string $orderId, SalesChannelContext $context): PaymentRouteResponse
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());

        $response = new ArrayStruct(['success' => false]);

        if ($order) {
            $orderTransaction = $order->getTransactions()->first();
            $customFields = $orderTransaction->getCustomFields();

            if (isset($customFields['xrpl'])) {
                $tx = $this->orderTransactionService->syncOrderTransactionWithXrpl($orderTransaction, $context->getContext());

                if ($tx) {
                    $response = new ArrayStruct([
                        'success' => true,
                        'hash' => $tx['hash'],
                        'ctid' => $tx['ctid']
                    ]);
                }
            }
        }

        return new PaymentRouteResponse($response);
    }

    /**
     * @Route("/store-api/ledger-direct/payment/price/{orderId}", name="store-api.ledger-direct.payment.price", methods={"GET", "POST"})
     */
    public function price(string $orderId, SalesChannelContext $context): PaymentRouteResponse
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());
        $orderTransaction = $order->getTransactions()->first();

        return new PaymentRouteResponse(new ArrayStruct(['todo' => 'implement']));
    }

    /**
     * @Route("/store-api/ledger-direct/payment/quote/{orderId}", name="store-api.ledger-direct.payment.quote", methods={"GET", "POST"})
     */
    public function quote(string $orderId, SalesChannelContext $context): PaymentRouteResponse
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());
        /** @var OrderTransactionEntity $orderTransaction */
        $orderTransaction = $order->getTransactions()->first();

        $customFields = $orderTransaction->getCustomFields();
        if (!isset($customFields['xrpl'])) {
            // TODO: Throw new Exception, this TA cannot be paid in XRP
        }

        return new PaymentRouteResponse(new ArrayStruct([
            'orderId' => $orderId,
            'orderNumber' => $order->getOrderNumber(),
            'currencyCode' => str_replace('XRP/','', $customFields['xrpl']['pairing']),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'price' => $orderTransaction->getAmount()->getTotalPrice(),
            'network' => $customFields['xrpl']['network'],
            'destinationAccount' => $customFields['xrpl']['destination_account'],
            'destinationTag' => $customFields['xrpl']['destination_tag'],
            'xrpAmount' => $customFields['xrpl']['amount_requested'],
            'exchangeRate' => $customFields['xrpl']['exchange_rate'],
            'showNoTransactionFoundError' => true,
        ]));
    }
}