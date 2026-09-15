<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Where the payment page sends the customer once the transaction is no
 * longer open — the one field of the status payload only the platform can
 * build (INVARIANTS.md, "Payment status": `redirect` is the adapter's).
 *
 * Preferably Shopware's own returnUrl: it runs the payment handler's
 * finalize() and lands on the finish page. Its payment token lives 30
 * minutes, though, and a customer who pays after 40 would run into
 * "token expired". Then the order page, which the deepLinkCode opens for
 * a guest too.
 *
 * The returnUrl is never passed through as given. Only its token is
 * taken and the URL rebuilt from the router — the customer's own request
 * carries it, and a payment page must not become an open redirect.
 */
final class PaymentRedirect
{
    private const TOKEN_PARAMETER = '_sw_payment_token';

    public function __construct(private readonly RouterInterface $router)
    {
    }

    /**
     * @param string|null $returnUrl the returnUrl Shopware handed pay(), as
     *     the page carried it along, or null when it is not known
     * @param int|null $now unix timestamp; defaults to the current time
     */
    public function target(OrderEntity $order, ?string $returnUrl, ?int $now = null): string
    {
        $token = self::usablePaymentToken($returnUrl, $now ?? time());

        if ($token !== null) {
            return $this->router->generate(
                'payment.finalize.transaction',
                [self::TOKEN_PARAMETER => $token],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        return $this->router->generate(
            'frontend.account.order.single.page',
            ['deepLinkCode' => (string) $order->getDeepLinkCode()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * The payment token from the returnUrl, if it is still valid. Only the
     * `exp` claim is read, without verifying the signature: whether the
     * token is genuine is Shopware's check at the finalize route. This only
     * decides whether sending the customer there can still work.
     */
    private static function usablePaymentToken(?string $returnUrl, int $now): ?string
    {
        if ($returnUrl === null || $returnUrl === '') {
            return null;
        }

        parse_str((string) parse_url($returnUrl, PHP_URL_QUERY), $query);
        $token = $query[self::TOKEN_PARAMETER] ?? null;

        if (!is_string($token) || $token === '') {
            return null;
        }

        $expiry = self::expiryOf($token);

        return $expiry !== null && $expiry > $now ? $token : null;
    }

    private static function expiryOf(string $jwt): ?int
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true);

        return is_array($claims) && is_int($claims['exp'] ?? null) ? $claims['exp'] : null;
    }
}
