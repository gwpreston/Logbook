<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

/**
 * The locales that ship a message catalogue, discovered from the
 * `translations/` directory (files named `<domain>.<locale>.php`).
 */
final readonly class AvailableLocales
{
    public const string FALLBACK = 'en';

    /**
     * @param list<string> $locales
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

    public function supports(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }
}
