<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use Collator;
use DateTimeZone;
use Locale;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Money\Currency;

/**
 * Option lists for preference pickers, labelled in the viewer's language.
 */
final class FormOptions
{
    /**
     * Languages with their regional formats, each named in itself
     * ("English (United Kingdom)"), alphabetically.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function locales(AvailableLocales $available): array
    {
        $options = array_map(
            static fn (string $code): array => ['value' => $code, 'label' => self::localeName($code)],
            $available->formattingLocales(),
        );

        $collator = new Collator('root');
        usort($options, static fn (array $a, array $b): int => (int) $collator->compare($a['label'], $b['label']));

        return $options;
    }

    /**
     * IANA time zones grouped by region ("Europe" → ["Europe/London", …]).
     *
     * @return array<string, list<string>>
     */
    public static function timezones(): array
    {
        $groups = [];
        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $groups[explode('/', $identifier, 2)[0]][] = $identifier;
        }

        /** @var array<string, list<string>> $groups region names are never numeric */
        return $groups;
    }

    /**
     * @return list<array{value: string, label: string}> "GBP — British Pound"
     */
    public static function currencies(string $displayLocale): array
    {
        return array_map(
            static fn (string $code): array => [
                'value' => $code,
                'label' => $code . ' — ' . Currency::name($code, $displayLocale),
            ],
            Currency::SUPPORTED,
        );
    }

    private static function localeName(string $code): string
    {
        $name = Locale::getDisplayName($code, $code);
        if (!is_string($name)) {
            return $code;
        }

        return mb_convert_case(mb_substr($name, 0, 1), MB_CASE_TITLE) . mb_substr($name, 1);
    }
}
