<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

use Logbook\Support\Csv\CsvReader;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;

/**
 * Chooses how to read a file (spec.md §7.13): a first guess from its header
 * and first rows, or the choices submitted on the mapping form.
 */
final class ImportMapper
{
    /** 2026-09-27, 27/09/2026, 9.27.2026, optionally followed by a time. */
    private const string DATE_LIKE = '/^(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4})'
        . '([T ]\d{1,2}:\d{2}(:\d{2})?)?$/';

    /**
     * @param list<ImportField> $fields
     */
    public static function guess(
        array $fields,
        CsvReader $csv,
        DisplayPreferences $preferences,
        ImportVocabulary $vocabulary,
    ): ImportOptions {
        $mapping = [];
        $taken = [];
        foreach ($fields as $field) {
            $mapping[$field->key] = null;
            foreach ($csv->header as $index => $header) {
                if (!isset($taken[$index]) && $vocabulary->headerMatches($field, $header)) {
                    $mapping[$field->key] = $index;
                    $taken[$index] = true;
                    break;
                }
            }
        }

        // A date field whose header was not recognised ("When") takes the
        // first free column that holds dates.
        foreach ($fields as $field) {
            if ($mapping[$field->key] !== null || !in_array($field->kind, [FieldKind::Date, FieldKind::DateTime], true)) {
                continue;
            }
            foreach (array_keys($csv->header) as $index) {
                $sample = self::firstValue($csv, $index);
                if (!isset($taken[$index]) && $sample !== null && preg_match(self::DATE_LIKE, $sample) === 1) {
                    $mapping[$field->key] = $index;
                    $taken[$index] = true;
                    break;
                }
            }
        }

        // Units and zone as named in the header ("Odometer (Miles)",
        // "Date and time (Europe/London)"), else the owner's.
        $distance = $preferences->distanceUnit;
        $zone = $preferences->timezone;
        $dateSample = null;
        foreach ($fields as $field) {
            $column = $mapping[$field->key];
            if ($column === null) {
                continue;
            }
            $bracketed = ImportVocabulary::bracketed($csv->header[$column] ?? '');
            if ($field->kind === FieldKind::Distance && $bracketed !== null) {
                $distance = $vocabulary->distanceUnit($bracketed) ?? $distance;
            }
            if ($field->kind === FieldKind::DateTime && $bracketed !== null && LocalTime::isValidTimezone($bracketed)) {
                $zone = $bracketed;
            }
            if (($field->kind === FieldKind::Date || $field->kind === FieldKind::DateTime) && $dateSample === null) {
                $dateSample = self::firstValue($csv, $column);
            }
        }

        return new ImportOptions(
            $mapping,
            self::dateOrder($dateSample, $preferences->locale),
            $distance,
            $preferences->volumeUnit,
            $zone,
        );
    }

    /**
     * The options submitted on the mapping form; anything missing or
     * invalid falls back to the guess.
     *
     * @param list<ImportField> $fields
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $fields, CsvReader $csv, array $query, ImportOptions $guess): ImportOptions
    {
        if (!array_key_exists('date_order', $query)) {
            return $guess;
        }

        $mapping = [];
        foreach ($fields as $field) {
            $value = $query['map_' . $field->key] ?? '';
            $index = is_string($value) && ctype_digit($value) ? (int) $value : null;
            $mapping[$field->key] = $index !== null && $index < count($csv->header) ? $index : null;
        }

        $string = static fn (string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';
        $zone = $string('zone');

        return new ImportOptions(
            $mapping,
            DateOrder::tryFrom($string('date_order')) ?? $guess->dateOrder,
            DistanceUnit::tryFrom($string('distance_unit')) ?? $guess->distanceUnit,
            VolumeUnit::tryFrom($string('volume_unit')) ?? $guess->volumeUnit,
            LocalTime::isValidTimezone($zone) ? $zone : $guess->timezone,
        );
    }

    private static function firstValue(CsvReader $csv, int $column): ?string
    {
        foreach ($csv->rows as $row) {
            $value = trim($row['cells'][$column] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * ISO when the file starts that way; otherwise day first, except in the
     * US (month first). The owner can always change it.
     */
    private static function dateOrder(?string $sample, string $locale): DateOrder
    {
        if ($sample === null || preg_match('/^\d{4}[-\/.]/', $sample) === 1) {
            return DateOrder::Iso;
        }

        return str_ends_with($locale, '_US') ? DateOrder::MonthFirst : DateOrder::DayFirst;
    }
}
