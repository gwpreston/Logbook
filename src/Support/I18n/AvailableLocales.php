<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

use ResourceBundle;

/**
 * The locales that ship a message catalogue, discovered from the
 * `translations/` directory (files named `<domain>.<locale>.php`).
 *
 * A user's locale may be more specific than a catalogue: "en_GB" is
 * translated with the "en" catalogue but formats dates, numbers and money the
 * British way. formattingLocales() lists every such choice.
 */
final readonly class AvailableLocales
{
    public const string FALLBACK = 'en';

    /**
     * @param list<string> $locales catalogue locales
     */
    public function __construct(public array $locales)
    {
    }

    public static function fromDirectory(string $directory): self
    {
        $locales = [];
        foreach (TranslationFiles::in($directory) as $file) {
            $locales[$file['locale']] = true;
        }
        $locales[self::FALLBACK] = true;

        $list = array_keys($locales);
        sort($list);

        return new self($list);
    }

    /**
     * Whether a catalogue exists for exactly this locale.
     */
    public function supports(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }

    /**
     * Whether $locale can be offered: a catalogue locale, or a regional
     * variant ICU knows ("en_GB") of a catalogue language.
     */
    public function supportsFormatting(string $locale): bool
    {
        return in_array($locale, $this->formattingLocales(), true);
    }

    /**
     * Catalogue locales plus the language_REGION variants ICU has for each
     * catalogue language.
     *
     * @return list<string>
     */
    public function formattingLocales(): array
    {
        /** @var array<string, list<string>> $cache */
        static $cache = [];
        $key = implode(',', $this->locales);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $languages = array_unique(array_map(static fn (string $l): string => explode('_', $l)[0], $this->locales));
        $result = $this->locales;

        foreach (self::icuLocales() as $locale) {
            if (
                preg_match('/^([a-z]{2,3})_[A-Z]{2}$/', $locale, $m) === 1
                && in_array($m[1], $languages, true)
                && !in_array($locale, $result, true)
            ) {
                $result[] = $locale;
            }
        }

        return $cache[$key] = $result;
    }

    /**
     * @return list<string>
     */
    private static function icuLocales(): array
    {
        /** @var list<string>|null $locales */
        static $locales = null;
        if ($locales === null) {
            $all = ResourceBundle::getLocales('');
            $locales = is_array($all) ? array_values(array_filter($all, 'is_string')) : [];
        }

        return $locales;
    }
}
