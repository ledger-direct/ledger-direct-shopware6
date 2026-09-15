<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Who may see an order's payment page and ask for its payment status.
 *
 * Key knowledge, not account membership: the payment-status contract
 * (INVARIANTS.md, "Payment status") wants a per-order secret and no login,
 * because guest checkout is the rule in crypto payments. Shopware's own
 * secret for that is the order's deepLinkCode — the code behind the guest
 * order link in the confirmation mail — so the same code opens the payment
 * page an hour later from that mail, in a fresh browser, guest or not.
 *
 * The fallback is the customer in the session whose id the order carries:
 * the way in from the account's order history, without a code. A guest has
 * a customer id for the length of the session, so this covers them too.
 *
 * Nothing here reveals whether an order exists: a wrong code and a missing
 * order both come back as null, and the callers answer both the same way.
 */
final class OrderAccessGuard
{
    public const DEEP_LINK_CODE_PARAMETER = 'deepLinkCode';

    public function __construct(private readonly OrderTransactionService $orderTransactionService)
    {
    }

    /**
     * The order with its transactions, or null when the request is not
     * allowed to see it.
     */
    public function authorisedOrder(string $orderId, Request $request, SalesChannelContext $context): ?OrderEntity
    {
        $order = $this->orderTransactionService->getOrderWithTransactions($orderId, $context->getContext());

        if ($order === null) {
            return null;
        }

        $code = (string) $request->get(self::DEEP_LINK_CODE_PARAMETER, '');
        $expected = (string) $order->getDeepLinkCode();

        if ($code !== '' && $expected !== '' && hash_equals($expected, $code)) {
            return $order;
        }

        $customerId = $context->getCustomer()?->getId();

        if ($customerId !== null && $customerId === $order->getOrderCustomer()?->getCustomerId()) {
            return $order;
        }

        return null;
    }
}
