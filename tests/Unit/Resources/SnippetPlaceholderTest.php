<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * The German storefront told customers to pay "%value% XRP": the snippet used
 * a placeholder the template never passes, so Symfony replaced nothing and the
 * amount was simply missing — on the plugin's home market, for every XRP order.
 *
 * A missing placeholder is invisible in review and silent at runtime, so it is
 * worth a test rather than a habit.
 */
class SnippetPlaceholderTest extends TestCase
{
    private const SNIPPET_DIRECTORY = __DIR__ . '/../../../src/Resources/snippet/storefront';

    private const REFERENCE_LOCALE = 'en-GB';

    public function testEveryTranslationUsesTheSamePlaceholdersAsTheReference(): void
    {
        $reference = $this->placeholdersByKey(self::REFERENCE_LOCALE);
        $translations = glob(self::SNIPPET_DIRECTORY . '/ledger-direct.*.json');

        $this->assertNotEmpty($translations, 'no snippet files found');

        foreach ($translations as $file) {
            $locale = $this->localeOf($file);

            if ($locale === self::REFERENCE_LOCALE) {
                continue;
            }

            foreach ($this->placeholdersByKey($locale) as $key => $placeholders) {
                $this->assertArrayHasKey($key, $reference, "{$locale}: '{$key}' does not exist in the reference");
                $this->assertSame(
                    $reference[$key],
                    $placeholders,
                    "{$locale}: placeholders of '{$key}' differ from " . self::REFERENCE_LOCALE
                );
            }
        }
    }

    /**
     * @return array<string, string[]> flat snippet key => sorted placeholders
     */
    private function placeholdersByKey(string $locale): array
    {
        $snippets = json_decode(
            (string) file_get_contents(self::SNIPPET_DIRECTORY . "/ledger-direct.{$locale}.json"),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $placeholders = [];

        foreach (self::flatten($snippets) as $key => $text) {
            preg_match_all('/%[a-zA-Z0-9_]+%/', $text, $matches);
            $found = array_unique($matches[0]);
            sort($found);
            $placeholders[$key] = $found;
        }

        return $placeholders;
    }

    /**
     * @param array<string, mixed> $snippets
     * @return array<string, string>
     */
    private static function flatten(array $snippets, string $prefix = ''): array
    {
        $flat = [];

        foreach ($snippets as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += self::flatten($value, $path);
                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }

    private function localeOf(string $file): string
    {
        return str_replace(['ledger-direct.', '.json'], '', basename($file));
    }
}
