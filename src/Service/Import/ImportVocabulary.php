<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

use BackedEnum;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The words an imported file may use (spec.md §7.13): header names, choice
 * labels, yes/no and unit names, each accepted as Logbook's code, in the
 * owner's language or in English (a file exported before a language change
 * still imports).
 */
final class ImportVocabulary
{
    private const array YES = ['yes', 'y', 'true', '1', 'x'];
    private const array NO = ['no', 'n', 'false', '0'];

    /** @var array<string, array<array-key, string>> cache: lookup name → normalised word → value */
    private array $lookups = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly string $locale,
    ) {
    }

    /**
     * Lower-case, without anything in brackets and without punctuation, so
     * "Odometer (Miles)", "odometer" and "ODOMETER:" all compare equal.
     */
    public static function normalise(string $text): string
    {
        $text = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', mb_strtolower($text)) ?? '';
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * The text inside the first brackets of a header ("Odometer (Miles)" → "Miles").
     */
    public static function bracketed(string $header): ?string
    {
        return preg_match('/[(\[]([^)\]]+)[)\]]/u', $header, $m) === 1 ? trim($m[1]) : null;
    }

    /**
     * Whether a CSV header names this field.
     */
    public function headerMatches(ImportField $field, string $header): bool
    {
        $name = self::normalise($header);
        if ($name === '') {
            return false;
        }

        $candidates = [
            self::normalise(str_replace('_', ' ', $field->key)),
            ...array_map(self::normalise(...), $field->aliases),
        ];
        foreach ($this->locales() as $locale) {
            // The export's headers carry a unit or zone in brackets; normalising drops it.
            $export = $this->translator->trans($field->exportKey, ['zone' => '', 'unit' => ''], null, $locale);
            $candidates[] = self::normalise($export);
            $candidates[] = $this->word($field->labelKey(), $locale);
        }

        return in_array($name, $candidates, true);
    }

    /**
     * A choice column's value as the enum's code, or null when unknown.
     *
     * @param class-string<BackedEnum> $enum
     */
    public function choice(string $enum, string $labelPrefix, string $value): ?string
    {
        $lookup = $this->lookup('choice:' . $enum, function () use ($enum, $labelPrefix): array {
            $words = [];
            foreach ($enum::cases() as $case) {
                $code = (string) $case->value;
                $words[self::normalise($code)] = $code;
                foreach ($this->locales() as $locale) {
                    $words[$this->word($labelPrefix . $code, $locale)] ??= $code;
                }
            }

            return $words;
        });

        return $lookup[self::normalise($value)] ?? null;
    }

    /**
     * A yes/no column's value, or null when it is neither.
     */
    public function flag(string $value): ?bool
    {
        $lookup = $this->lookup('flag', function (): array {
            $words = array_fill_keys(self::YES, '1') + array_fill_keys(self::NO, '0');
            foreach ($this->locales() as $locale) {
                $words[$this->word('export.yes', $locale)] ??= '1';
                $words[$this->word('export.no', $locale)] ??= '0';
            }

            return $words;
        });

        $word = $lookup[self::normalise($value)] ?? null;

        return $word === null ? null : $word === '1';
    }

    /**
     * A volume unit name, code or symbol; 'kwh' for kilowatt-hours; null when unknown.
     */
    public function volumeUnit(string $value): VolumeUnit|string|null
    {
        $lookup = $this->lookup('volume', function (): array {
            $words = ['kwh' => 'kwh'];
            foreach ($this->locales() as $locale) {
                $words[$this->word('units.name.kwh', $locale)] ??= 'kwh';
                $words[$this->word('units.symbol.kwh', $locale)] ??= 'kwh';
            }

            return $words + $this->unitWords(VolumeUnit::cases());
        });

        $word = $lookup[self::normalise($value)] ?? null;

        return $word === null || $word === 'kwh' ? $word : VolumeUnit::from($word);
    }

    /**
     * A distance unit named in a header's brackets ("Odometer (Miles)"), or null.
     */
    public function distanceUnit(string $value): ?DistanceUnit
    {
        $lookup = $this->lookup('distance', fn (): array => $this->unitWords(DistanceUnit::cases()));
        $word = $lookup[self::normalise($value)] ?? null;

        return $word === null ? null : DistanceUnit::from($word);
    }

    /**
     * Each unit's code, name and symbol → its code.
     *
     * @param list<DistanceUnit|VolumeUnit> $units
     * @return array<array-key, string>
     */
    private function unitWords(array $units): array
    {
        $words = [];
        foreach ($units as $unit) {
            $words[self::normalise($unit->value)] ??= $unit->value;
            foreach ($this->locales() as $locale) {
                $words[$this->word('units.name.' . $unit->value, $locale)] ??= $unit->value;
                $words[$this->word('units.symbol.' . $unit->value, $locale)] ??= $unit->value;
            }
        }

        return $words;
    }

    private function word(string $key, string $locale): string
    {
        return self::normalise($this->translator->trans($key, [], null, $locale));
    }

    /**
     * @param callable(): array<array-key, string> $build normalised word → value
     * @return array<array-key, string>
     */
    private function lookup(string $name, callable $build): array
    {
        // Numeric words ("1", "0") become integer keys; look-ups by string still find them.
        return $this->lookups[$name] ??= array_filter(
            $build(),
            static fn (int|string $k): bool => $k !== '',
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return array_values(array_unique([$this->locale, AvailableLocales::FALLBACK]));
    }
}
