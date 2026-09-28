<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Which logo the payment page shows in its header.
 *
 *   shop    the sales channel's theme logo — resolved in the template with
 *           theme_config('sw-logo-desktop'), the way every storefront page
 *           gets it; nothing to look up here
 *   custom  a picture from the media library, by the media id the merchant
 *           chose — resolved here, and only from this shop's media, never
 *           from a URL a merchant typed in (a third-party host would learn
 *           the IP of every paying customer, and could break as mixed content)
 *   none    a monogram of the shop name
 *
 * The result is always rendered as an <img> with fixed maximum dimensions,
 * never as inline SVG from merchant data. When the picture cannot be
 * resolved, the monogram takes over.
 */
final class PageLogo
{
    public function __construct(
        private readonly ConfigurationService $configuration,
        private readonly EntityRepository $mediaRepository,
    ) {
    }

    /**
     * @return array{mode: string, url: string|null, monogram: string}
     */
    public function forShop(string $shopName, Context $context): array
    {
        $mode = $this->configuration->getPaymentPageLogoMode();
        $url = null;

        if ($mode === ConfigurationService::LOGO_MODE_CUSTOM) {
            $url = $this->customLogoUrl($context);

            if ($url === null) {
                $mode = ConfigurationService::LOGO_MODE_NONE;
            }
        }

        return [
            'mode' => $mode,
            'url' => $url,
            'monogram' => self::monogram($shopName),
        ];
    }

    /** The first letter of the shop name, upper-cased, multibyte-safe; a placeholder when there is none. */
    public static function monogram(string $shopName): string
    {
        $trimmed = trim($shopName);

        if ($trimmed === '') {
            return '·';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }

    private function customLogoUrl(Context $context): ?string
    {
        $mediaId = $this->configuration->getPaymentPageLogoMediaId();

        if ($mediaId === null) {
            return null;
        }

        $media = $this->mediaRepository->search(new Criteria([$mediaId]), $context)->getEntities()->first();

        if (!$media instanceof MediaEntity) {
            return null;
        }

        $url = $media->getUrl();

        return $url === '' ? null : $url;
    }
}
