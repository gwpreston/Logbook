<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

/**
 * Picks the request locale. Order of preference:
 *   1. the signed-in user's saved locale
 *   2. the browser's Accept-Language, matched against shipped catalogues
 *   3. APP_LOCALE
 *   4. English
 *
 * The result keeps a known region ("de-AT" → "de_AT"): the translator falls
 * back from "de_AT" to the "de" catalogue, while dates, numbers and money are
 * formatted the Austrian way.
 */
final readonly class LocaleResolver
{
    public function __construct(
        private AvailableLocales $available,
        private string $defaultLocale,
    ) {
    }

    public function resolve(?string $acceptLanguage, ?string $preferred = null): string
    {
        if ($preferred !== null) {
            $match = $this->match($preferred);
            if ($match !== null) {
                return $match;
            }
        }

        foreach ($this->parseAcceptLanguage($acceptLanguage ?? '') as $candidate) {
            $match = $this->match($candidate);
            if ($match !== null) {
                return $match;
            }
        }

        return $this->match($this->defaultLocale) ?? AvailableLocales::FALLBACK;
    }

    /**
     * Normalise a tag like "en-gb" / "en_GB" and match it: an exact
     * catalogue, else the language with its region when ICU knows the
     * combination, else the bare language.
     */
    private function match(string $tag): ?string
    {
        $parts = preg_split('/[-_]/', trim($tag)) ?: [];
        $language = strtolower($parts[0] ?? '');
        if ($language === '' || $language === '*') {
            return null;
        }

        $region = isset($parts[1]) ? strtoupper($parts[1]) : null;
        if ($region !== null && $this->available->supportsFormatting($language . '_' . $region)) {
            return $language . '_' . $region;
        }

        return $this->available->supports($language) ? $language : null;
    }

    /**
     * @return list<string> language tags ordered by descending q-value
     */
    private function parseAcceptLanguage(string $header): array
    {
        $weighted = [];
        foreach (explode(',', $header) as $position => $part) {
            $segments = explode(';', trim($part));
            $tag = trim($segments[0]);
            if ($tag === '') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($segments, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([01](?:\.\d{0,3})?)\s*$/i', $parameter, $m) === 1) {
                    $quality = (float) $m[1];
                }
            }

            if ($quality > 0) {
                $weighted[] = ['tag' => $tag, 'q' => $quality, 'pos' => $position];
            }
        }

        usort($weighted, static fn (array $a, array $b): int => [$b['q'], $a['pos']] <=> [$a['q'], $b['pos']]);

        return array_map(static fn (array $entry): string => $entry['tag'], $weighted);
    }
}
