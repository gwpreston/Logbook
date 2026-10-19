<?php

declare(strict_types=1);

namespace Logbook\Service\Scan\Rules;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The words reading without AI looks for (spec.md §7.27 *Reading without
 * AI*, Phase 53.4): `scan.keywords.<list>` in the locale files, a list of
 * words or phrases separated by `|`. The user's locale's words and
 * English are both used, so an English receipt still reads under `de`.
 */
final class Keywords
{
    private const string FALLBACK = 'en';

    /** @var array<string, string> */
    private array $patterns = [];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @return list<string> lower case, longest first
     */
    public function words(string $list, string $locale): array
    {
        $words = [];
        foreach (array_unique([$locale, self::FALLBACK, substr($locale, 0, 2)]) as $each) {
            $id = 'scan.keywords.' . $list;
            $text = $this->translator->trans($id, [], null, $each);
            if ($text === $id) {
                continue;
            }
            foreach (explode('|', $text) as $word) {
                $word = mb_strtolower(trim($word));
                if ($word !== '') {
                    $words[$word] = true;
                }
            }
        }
        $words = array_map('strval', array_keys($words));
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));

        return $words;
    }

    /**
     * A pattern matching any of the list's words as whole words, in any
     * case; spaces in a phrase match any run of spaces.
     */
    public function pattern(string $list, string $locale): string
    {
        $key = $list . '/' . $locale;
        $this->patterns[$key] ??= '/(?<![\p{L}\p{N}])' . $this->alternation($list, $locale) . '(?![\p{L}\p{N}])/iu';

        return $this->patterns[$key];
    }

    /**
     * The list's words as one group, `(?:a|b)`, for a larger pattern
     * (with the `i` and `u` flags); a group that never matches when the
     * list is empty.
     */
    public function alternation(string $list, string $locale): string
    {
        $words = array_map(
            static fn (string $w): string => (string) preg_replace('/\\\\ |\s+/', '\s+', preg_quote($w, '/')),
            $this->words($list, $locale),
        );

        return $words === [] ? '(?!)' : '(?:' . implode('|', $words) . ')';
    }

    /**
     * The list's different words found in $text.
     *
     * @return list<string>
     */
    public function found(string $list, string $locale, string $text): array
    {
        if (preg_match_all($this->pattern($list, $locale), $text, $m) === false) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $w): string => (string) preg_replace('/\s+/u', ' ', mb_strtolower($w)),
            $m[0],
        )));
    }

    public function has(string $list, string $locale, string $text): bool
    {
        return preg_match($this->pattern($list, $locale), $text) === 1;
    }
}
