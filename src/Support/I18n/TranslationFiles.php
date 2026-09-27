<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

/**
 * Enumerates PHP message catalogues named `<domain>.<locale>.php`, e.g.
 * `messages+intl-icu.en.php` (the `+intl-icu` suffix enables ICU MessageFormat).
 */
final class TranslationFiles
{
    /**
     * @return list<array{path: string, domain: string, locale: string}>
     */
    public static function in(string $directory): array
    {
        $files = glob(rtrim($directory, '/\\') . '/*.php');
        if ($files === false) {
            return [];
        }

        $found = [];
        foreach ($files as $path) {
            $pattern = '/^(?<domain>[A-Za-z0-9_+-]+)\.(?<locale>[a-z]{2,3}(?:_[A-Z]{2})?)\.php$/';
            if (preg_match($pattern, basename($path), $m) === 1) {
                $found[] = ['path' => $path, 'domain' => $m['domain'], 'locale' => $m['locale']];
            }
        }

        return $found;
    }
}
