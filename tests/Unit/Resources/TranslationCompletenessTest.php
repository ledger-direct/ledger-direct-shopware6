<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every text a customer or a merchant reads exists in all six languages, and
 * none of them is English in disguise. A missing key renders as the key, a
 * missing placeholder drops the amount out of a sentence, and an English
 * sentence left in a French file looks translated in review - in other
 * plugins exactly that slipped through. English (en-GB) is the reference.
 *
 * Covered: the storefront snippets, the administration snippets and the
 * plugin configuration (config.xml: every title, label, help text and option
 * name in every language).
 */
class TranslationCompletenessTest extends TestCase
{
    private const RESOURCES = __DIR__ . '/../../../src/Resources';

    private const REFERENCE = 'en-GB';

    private const LOCALES = ['de-DE', 'fr-FR', 'nl-NL', 'es-ES', 'pt-PT'];

    /**
     * Values that may equal the English one: names that are never translated
     * (Uebersetzungen-Glossar.md), a formula, and the glossary terms that stay
     * English ("destination tag" everywhere, "asset" in Dutch and German,
     * "transaction" is the French word too). Keep this list short.
     */
    private const SAME_AS_ENGLISH = [
        'LedgerDirect',
        'CTID',
        'Asset',
        'Transaction',
        'Destination tag',
        '1 %asset% = %rate% %currency%',
        'XRPL Mainnet',
        'XRPL Testnet',
        'Stellar Mainnet',
        'Stellar Testnet',
        'Xahau Mainnet',
        'Xahau Testnet',
        'LedgerDirect - XRP Ledger',
        'LedgerDirect - Xahau',
    ];

    /**
     * English words that have no business in another language's text. Not a
     * spell check - a net for half-translated sentences ("Payer avec your wallet").
     */
    private const ENGLISH_WORDS = ['the', 'and', 'your', 'please', 'with', 'from', 'payment', 'order', 'amount', 'address', 'received', 'issuer', 'cannot', 'should', 'only', 'checkout'];

    private const PLACEHOLDER = '/%[A-Za-z0-9_]+%|\{\{.*?\}\}|\{[A-Za-z0-9_]+\}|%(?:\d+\$)?[sd]|<\/?[a-zA-Z][^>]*>/';

    public function testTheSnippetFilesAreExactlyTheSixLanguages(): void
    {
        $expected = [self::REFERENCE, ...self::LOCALES];
        sort($expected);

        $storefront = array_map(
            static fn (string $file): string => str_replace(['ledger-direct.', '.json'], '', basename($file)),
            (array) glob(self::RESOURCES . '/snippet/storefront/ledger-direct.*.json')
        );
        $administration = array_map(
            static fn (string $file): string => basename($file, '.json'),
            (array) glob(self::RESOURCES . '/app/administration/src/snippet/*.json')
        );
        sort($storefront);
        sort($administration);

        $this->assertSame($expected, $storefront, 'storefront snippet files');
        $this->assertSame($expected, $administration, 'administration snippet files');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function snippetSources(): iterable
    {
        yield 'storefront' => ['/snippet/storefront/ledger-direct.%s.json'];
        yield 'administration' => ['/app/administration/src/snippet/%s.json'];
    }

    #[DataProvider('snippetSources')]
    public function testEverySnippetIsTranslated(string $pattern): void
    {
        $reference = self::snippets($pattern, self::REFERENCE);
        $this->assertNotEmpty($reference);

        foreach (self::LOCALES as $locale) {
            $translation = self::snippets($pattern, $locale);

            // Missing or additional keys, whatever their order in the file.
            $expected = array_keys($reference);
            $actual = array_keys($translation);
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "{$locale}: the keys differ from " . self::REFERENCE);

            foreach ($reference as $key => $english) {
                self::assertTranslation($english, $translation[$key], "{$locale}: {$key}");
            }
        }
    }

    public function testEveryConfigurationTextIsTranslated(): void
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->load(self::RESOURCES . '/config/config.xml'));
        $checked = 0;

        foreach ((new \DOMXPath($document))->query('//*') as $parent) {
            /** @var array<string, array<string, list<string>>> $texts tag => lang => values */
            $texts = [];

            foreach ($parent->childNodes as $child) {
                if (!$child instanceof \DOMElement || !self::isTranslatable($child)) {
                    continue;
                }

                $lang = $child->hasAttribute('lang') ? $child->getAttribute('lang') : self::REFERENCE;
                $texts[$child->localName][$lang][] = $child->textContent;
            }

            foreach ($texts as $tag => $byLang) {
                $where = $tag . ' in ' . self::describe($parent);
                $this->assertSame(1, count($byLang[self::REFERENCE] ?? []), "{$where}: one English text without lang");

                foreach (self::LOCALES as $locale) {
                    $this->assertSame(1, count($byLang[$locale] ?? []), "{$where}: exactly one lang=\"{$locale}\"");
                    self::assertTranslation($byLang[self::REFERENCE][0], $byLang[$locale][0], "{$locale}: {$where}");
                }

                $this->assertSame([], array_diff(array_keys($byLang), [self::REFERENCE, ...self::LOCALES]), "{$where}: unexpected language");
                ++$checked;
            }
        }

        $this->assertGreaterThan(40, $checked, 'config.xml texts found');
    }

    private static function assertTranslation(string $english, string $translation, string $where): void
    {
        self::assertNotSame('', trim($translation), "{$where}: empty");
        self::assertSame(self::placeholders($english), self::placeholders($translation), "{$where}: placeholders differ from English");

        if ($translation === $english) {
            self::assertContains($translation, self::SAME_AS_ENGLISH, "{$where}: identical to English: \"{$translation}\"");

            return;
        }

        $words = preg_split('/[^\p{L}]+/u', mb_strtolower((string) preg_replace(self::PLACEHOLDER, ' ', $translation)), -1, PREG_SPLIT_NO_EMPTY);
        $english = array_values(array_intersect((array) $words, self::ENGLISH_WORDS));
        self::assertSame([], $english, "{$where}: English words in \"{$translation}\"");
    }

    /**
     * @return list<string> sorted, with repetitions
     */
    private static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);
        $found = array_map(static fn (string $p): string => (string) preg_replace('/\s+/', '', $p), $matches[0]);
        sort($found);

        return $found;
    }

    /**
     * A placeholder is a URL template, not text; every other element here is.
     */
    private static function isTranslatable(\DOMElement $element): bool
    {
        if (in_array($element->localName, ['title', 'label', 'helpText'], true)) {
            return true;
        }

        return $element->localName === 'name' && $element->parentNode instanceof \DOMElement && $element->parentNode->localName === 'option';
    }

    private static function describe(\DOMNode $node): string
    {
        if ($node instanceof \DOMElement) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement && in_array($child->localName, ['name', 'id'], true)) {
                    return $node->localName . ' ' . $child->textContent;
                }
            }
        }

        return $node->nodeName . ' (line ' . $node->getLineNo() . ')';
    }

    /**
     * @return array<string, string> flat key => text
     */
    private static function snippets(string $pattern, string $locale): array
    {
        $json = json_decode(
            (string) file_get_contents(self::RESOURCES . sprintf($pattern, $locale)),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return self::flatten($json);
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
}
