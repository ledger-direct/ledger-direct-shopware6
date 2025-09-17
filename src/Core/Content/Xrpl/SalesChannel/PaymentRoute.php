<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Core\Content\Xrpl\SalesChannel;

use DateTimeImmutable;
use Hardcastle\LedgerDirect\Service\OrderTransactionService;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Annotation\Route;

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
     * @Route("/store-api/ledger-direct/payment/check/{orderId}", name="store-api.ledger-direct.payment.check", methods={"GET", "POST"}, defaults={"_loginRequired"=true})
     */
    public function check(string $orderId, SalesChannelContext $context): PaymentRouteResponse
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());

        $response = new ArrayStruct(['success' => false]);

        if ($order) {
            $orderTransaction = $order->getTransactions()->first();
            $customFields = $orderTransaction->getCustomFields();

            if (isset($customFields['ledger_direct'])) {
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
        $customer = $context->getCustomer();

        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());
        $orderTransaction = $order->getTransactions()->first();

        if (!$customer || $customer->getId() !== $order->getOrderCustomer()->getCustomerId()) {
            throw CartException::customerNotLoggedIn();
        }

        return new PaymentRouteResponse(new ArrayStruct(['todo' => 'implement']));
    }

    /**
     * @Route("/store-api/ledger-direct/payment/quote/{orderId}", name="store-api.ledger-direct.payment.quote", methods={"GET", "POST"})
     */
    public function quote(string $orderId, SalesChannelContext $context): PaymentRouteResponse
    {
        $customer = $context->getCustomer();

        if (!$customer) {
            throw CartException::customerNotLoggedIn();
        }

        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());

        if ($customer->getId() !== $order->getOrderCustomer()->getCustomerId()) {
            throw CartException::insufficientPermission();
        }

        /** @var OrderTransactionEntity $orderTransaction */
        $orderTransaction = $order->getTransactions()->first();

        $tsOrder = $orderTransaction->getCreatedAt()->getTimestamp();
        $tsNow = (new DateTimeImmutable('now'))->getTimestamp();
        if ($tsNow - $tsOrder > 3600) {
            //throw new TransactionLifetimeException('This transaction is not valid anymore');
        }

        $customFields = $orderTransaction->getCustomFields();
         if (!isset($customFields['ledger_direct'])) {
            // TODO: Throw Exception, this TA cannot be paid in XRP
        }

        return new PaymentRouteResponse(new ArrayStruct([
            'orderId' => $orderId,
            'orderNumber' => $order->getOrderNumber(),
            'currencyCode' => str_replace('XRP/','', $customFields['ledger_direct']['pairing']),
            'currencySymbol' => $order->getCurrency()->getSymbol(),
            'price' => $orderTransaction->getAmount()->getTotalPrice(),
            'network' => $customFields['ledger_direct']['network'],
            'destinationAccount' => $customFields['ledger_direct']['destination_account'],
            'destinationTag' => $customFields['ledger_direct']['destination_tag'],
            'xrpAmount' => $customFields['ledger_direct']['amount_requested'],
            'exchangeRate' => $customFields['ledger_direct']['exchange_rate'],
            'showNoTransactionFoundError' => true,
        ]));
    }
}