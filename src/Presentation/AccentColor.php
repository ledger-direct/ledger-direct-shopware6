<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Presentation;

/**
 * The one colour a merchant sets on the payment page: buttons, the countdown
 * bar, the destination tag's frame — everything drawn in --ld-accent, with
 * white on top of it.
 *
 * A colour that does not carry white text legibly (WCAG contrast below
 * 4.5 : 1) is not used; the page falls back to the default rather than
 * rendering an unreadable button. So does anything that is not a hex colour.
 */
final class AccentColor
{
    public const DEFAULT = '#1f5eff';

    /** WCAG 2 minimum for normal text. */
    public const MIN_CONTRAST_TO_WHITE = 4.5;

    public static function sanitize(?string $stored): string
    {
        $color = self::normalize($stored);

        if ($color === null || self::contrastToWhite($color) < self::MIN_CONTRAST_TO_WHITE) {
            return self::DEFAULT;
        }

        return $color;
    }

    /** `#rgb` or `#rrggbb`, case-insensitive, to lowercase `#rrggbb`; anything else is null. */
    public static function normalize(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m) === 1) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /** WCAG 2 contrast ratio between a `#rrggbb` colour and white. */
    public static function contrastToWhite(string $color): float
    {
        return (1.0 + 0.05) / (self::relativeLuminance($color) + 0.05);
    }

    private static function relativeLuminance(string $color): float
    {
        $channels = [];

        foreach ([1, 3, 5] as $offset) {
            $srgb = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $srgb <= 0.03928 ? $srgb / 12.92 : (($srgb + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
