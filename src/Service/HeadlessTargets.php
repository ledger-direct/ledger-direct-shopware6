<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Service;

use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Where an external front end — Shopware Frontends, a React or Nuxt app,
 * anything that is not the storefront — takes over from the plugin.
 *
 * Two URL templates in the configuration, per sales channel, both empty by
 * default: the payment page the customer is sent to after the order, and
 * the order page they land on when a payment arrives after Shopware's
 * payment token has expired. Empty means the storefront's own pages, as
 * before; a storefront sales channel never notices this class.
 *
 * The targets come from the merchant's configuration and from nowhere
 * else — not from the request, not from the payment token — so the payment
 * page cannot be turned into an open redirect. Placeholders are filled in
 * URL-encoded; a template is checked when it is saved (ConfigurationValidator).
 */
final class HeadlessTargets
{
    public const PAYMENT_PAGE_KEY = 'headlessPaymentPageUrl';

    public const ORDER_PAGE_KEY = 'headlessOrderPageUrl';

    /** What the payment page template may name, and what it must name. */
    public const PAYMENT_PAGE_PLACEHOLDERS = ['orderId', 'deepLinkCode', 'returnUrl'];

    public const PAYMENT_PAGE_REQUIRED = ['orderId', 'deepLinkCode'];

    /** The order page: the code opens the order for a guest, the id is a convenience. */
    public const ORDER_PAGE_PLACEHOLDERS = ['orderId', 'deepLinkCode'];

    public const ORDER_PAGE_REQUIRED = ['deepLinkCode'];

    public function __construct(private readonly ConfigurationService $configuration)
    {
    }

    /**
     * The front end's payment page for this order, or null when the order's
     * sales channel has none configured — then the storefront's page it is.
     */
    public function paymentPageUrl(OrderEntity $order, string $returnUrl): ?string
    {
        $template = $this->configuration->getStringFor(self::PAYMENT_PAGE_KEY, $order->getSalesChannelId());

        if ($template === '') {
            return null;
        }

        return self::render($template, [
            'orderId' => $order->getId(),
            'deepLinkCode' => (string) $order->getDeepLinkCode(),
            'returnUrl' => $returnUrl,
        ]);
    }

    /**
     * The front end's order page, or null when none is configured for the
     * order's sales channel.
     */
    public function orderPageUrl(OrderEntity $order): ?string
    {
        $template = $this->configuration->getStringFor(self::ORDER_PAGE_KEY, $order->getSalesChannelId());

        if ($template === '') {
            return null;
        }

        return self::render($template, [
            'orderId' => $order->getId(),
            'deepLinkCode' => (string) $order->getDeepLinkCode(),
        ]);
    }

    /**
     * `{name}` → the value, URL-encoded, so a returnUrl with its own query
     * string survives inside the template's.
     *
     * @param array<string, string> $values
     */
    public static function render(string $template, array $values): string
    {
        $replacements = [];

        foreach ($values as $name => $value) {
            $replacements['{' . $name . '}'] = rawurlencode($value);
        }

        return strtr($template, $replacements);
    }

    /**
     * Why a template cannot be used, or null when it can: an absolute https
     * URL (http only for a local development host), every required
     * placeholder present, no unknown one.
     *
     * The redirect carries the order's deepLinkCode and, in {returnUrl},
     * Shopware's payment token: over plain http both would cross the wire in
     * the clear for anyone on the path to read. A front end on localhost is
     * the one place that is not a wire.
     *
     * @param list<string> $allowed
     * @param list<string> $required
     */
    public static function problem(string $template, array $allowed, array $required): ?string
    {
        // parse_url() reads `http://evil.example\@localhost/` as host localhost; a browser does not.
        // Nothing a URL template legitimately needs is a backslash, a space, a control character
        // or credentials in the authority, so all of them are refused before the host is looked at.
        if (preg_match('/[\\\\\s\x00-\x1f\x7f]/', $template) === 1) {
            return 'must not contain backslashes, spaces or control characters';
        }

        if (parse_url($template, PHP_URL_USER) !== null || parse_url($template, PHP_URL_PASS) !== null) {
            return 'must not carry credentials in front of the host';
        }

        $scheme = strtolower((string) parse_url($template, PHP_URL_SCHEME));
        $host = parse_url($template, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            return 'must be an absolute URL starting with https://';
        }

        if ($scheme === 'http' && !self::isLocalHost($host)) {
            return 'must use https:// — the code and the payment token it carries must not cross the wire in the clear (http:// is allowed for localhost only)';
        }

        preg_match_all('/\{([^{}]*)\}/', $template, $matches);
        $named = $matches[1];

        foreach ($required as $placeholder) {
            if (!in_array($placeholder, $named, true)) {
                return 'must contain {' . $placeholder . '}';
            }
        }

        foreach ($named as $placeholder) {
            if (!in_array($placeholder, $allowed, true)) {
                return 'names an unknown placeholder {' . $placeholder . '}; known are {' . implode('}, {', $allowed) . '}';
            }
        }

        return null;
    }

    /** localhost, 127.0.0.1, ::1, and the .localhost and .test names a developer's machine answers for. */
    private static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }
}
