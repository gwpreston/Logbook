<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices\Uk;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\FeedPrice;
use Logbook\Domain\FuelPrices\FeedStation;
use Logbook\Service\FuelPrices\FeedReport;
use Logbook\Support\Number\Decimal;

/**
 * Reads UK Fuel Finder's station and price records (spec.md §4, §7.34): the
 * fields of the developer portal's specification, checked one by one;
 * anything else in a record is ignored and nothing from it is ever run.
 *
 * - Prices are pence per litre as strings ("0135.9000"). A value under 2.0
 *   was entered in pounds and is multiplied by 100; one outside 50–500p
 *   after that is dropped and counted (decided 2026-10-03, #141). They are
 *   returned in pounds with three places.
 * - Times have no zone and are read as UTC.
 * - Upper-case names and addresses ("TESCO ANTRIM") are title-cased.
 */
final class FuelFinderParser
{
    public const string MIN_PENCE = '50';
    public const string MAX_PENCE = '500';
    /** Below this a price was typed in pounds. */
    public const string POUNDS_BELOW = '2';

    private const string DECIMAL = '/^\s*(\d{1,6})(?:\.(\d{1,8}))?\s*$/';
    private const string TIME = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * @param array<mixed> $records one page of `GET /api/v1/pfs`
     * @param array<string, FuelGrade> $gradeMap
     * @return list<FeedStation>
     */
    public static function stations(array $records, array $gradeMap, FeedReport $report): array
    {
        $stations = [];
        foreach ($records as $record) {
            $station = is_array($record) ? self::station($record, $gradeMap) : null;
            if ($station === null) {
                $report->invalid++;
                continue;
            }
            $stations[] = $station;
        }

        return $stations;
    }

    /**
     * @param array<mixed> $records one page of `GET /api/v1/pfs/fuel-prices`
     * @param array<string, FuelGrade> $gradeMap
     * @return list<FeedPrice>
     */
    public static function prices(array $records, array $gradeMap, FeedReport $report): array
    {
        $prices = [];
        foreach ($records as $record) {
            $ref = is_array($record) ? self::ref($record['node_id'] ?? null) : null;
            $entries = is_array($record) ? ($record['fuel_prices'] ?? null) : null;
            if ($ref === null || !is_array($entries)) {
                $report->invalid++;
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    $report->invalid++;
                    continue;
                }
                $code = $entry['fuel_type'] ?? null;
                $grade = is_string($code) ? ($gradeMap[strtoupper(trim($code))] ?? null) : null;
                if ($grade === null) {
                    $report->unknownGrades++;
                    continue;
                }
                $raw = $entry['price'] ?? null;
                $time = $entry['price_last_updated'] ?? null;
                if ($raw === null || $time === null) {
                    // Registered but not yet priced: nothing to list.
                    continue;
                }
                $reportedAt = self::time($time);
                if ($reportedAt === null) {
                    $report->invalid++;
                    continue;
                }
                $price = self::price($raw, $report);
                if ($price === null) {
                    continue;
                }
                $prices[] = new FeedPrice($ref, $grade, $price, $reportedAt);
            }
        }

        return $prices;
    }

    /**
     * Pence (as given) to pounds per litre with three places, or null when
     * implausible (counted in the report).
     */
    public static function price(mixed $raw, FeedReport $report): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = is_int($raw) ? (string) $raw : Decimal::fromFloat($raw, 4);
        }
        if (!is_string($raw) || preg_match(self::DECIMAL, $raw, $m) !== 1) {
            $report->invalid++;

            return null;
        }
        $pence = (ltrim($m[1], '0') === '' ? '0' : ltrim($m[1], '0')) . (isset($m[2]) ? '.' . $m[2] : '');
        if (Decimal::compare($pence, self::POUNDS_BELOW) < 0 && Decimal::compare($pence, '0') > 0) {
            $pence = Decimal::multiply($pence, '100', 4);
            $report->corrected++;
        }
        if (Decimal::compare($pence, self::MIN_PENCE) < 0 || Decimal::compare($pence, self::MAX_PENCE) > 0) {
            $report->implausible++;

            return null;
        }

        return Decimal::divide($pence, '100', 3);
    }

    /**
     * An ISO 8601 time, read as UTC when it has no zone.
     */
    public static function time(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::TIME, trim($value)) !== 1) {
            return null;
        }
        try {
            $time = new DateTimeImmutable(trim($value), new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param array<mixed> $record
     * @param array<string, FuelGrade> $gradeMap
     */
    private static function station(array $record, array $gradeMap): ?FeedStation
    {
        $ref = self::ref($record['node_id'] ?? null);
        $name = self::text($record['trading_name'] ?? null, 150);
        if ($ref === null || $name === null) {
            return null;
        }
        $location = is_array($record['location'] ?? null) ? $record['location'] : [];
        [$latitude, $longitude] = self::position($location['latitude'] ?? null, $location['longitude'] ?? null);

        $grades = [];
        foreach (is_array($record['fuel_types'] ?? null) ? $record['fuel_types'] : [] as $code) {
            $grade = is_string($code) ? ($gradeMap[strtoupper(trim($code))] ?? null) : null;
            if ($grade !== null && !in_array($grade, $grades, true)) {
                $grades[] = $grade;
            }
        }
        $amenities = [];
        foreach (is_array($record['amenities'] ?? null) ? $record['amenities'] : [] as $amenity) {
            if (is_string($amenity) && preg_match('/^[a-z0-9_]{1,50}$/', $amenity) === 1) {
                $amenities[] = $amenity;
            }
        }
        $hours = is_array($record['opening_times'] ?? null) ? self::openingHours($record['opening_times']) : null;
        $postcode = self::text($location['postcode'] ?? null, 20);

        return new FeedStation(
            ref: $ref,
            name: self::titleCase($name),
            brand: ($brand = self::text($record['brand_name'] ?? null, 100)) === null ? null : self::titleCase($brand),
            address: self::address($location),
            postcode: $postcode === null ? null : strtoupper($postcode),
            latitude: $latitude,
            longitude: $longitude,
            openingHours: $hours,
            amenities: array_values(array_unique($amenities)),
            grades: $grades,
            temporarilyClosed: ($record['temporary_closure'] ?? false) === true,
            permanentlyClosed: ($record['permanent_closure'] ?? false) === true,
        );
    }

    private static function ref(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value) === 1 ? $value : null;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $value));
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $text);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    /**
     * "TESCO ANTRIM" → "Tesco Antrim"; mixed case is left as typed.
     */
    private static function titleCase(string $text): string
    {
        return mb_strtoupper($text) === $text && mb_strtolower($text) !== $text
            ? mb_convert_case(mb_strtolower($text), MB_CASE_TITLE)
            : $text;
    }

    /**
     * @param array<mixed> $location
     */
    private static function address(array $location): ?string
    {
        $parts = [];
        foreach (['address_line_1', 'address_line_2', 'city'] as $field) {
            $part = self::text($location[$field] ?? null, 300);
            if ($part === null) {
                continue;
            }
            $part = self::titleCase($part);
            $joined = mb_strtolower(implode(', ', $parts));
            if (!str_contains($joined, mb_strtolower($part))) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : mb_substr(implode(', ', $parts), 0, 300);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function position(mixed $latitude, mixed $longitude): array
    {
        $lat = is_numeric($latitude) ? (float) $latitude : null;
        $lon = is_numeric($longitude) ? (float) $longitude : null;
        if (
            $lat === null || $lon === null || !is_finite($lat) || !is_finite($lon)
            || abs($lat) > 90.0 || abs($lon) > 180.0 || ($lat === 0.0 && $lon === 0.0)
        ) {
            return [null, null];
        }

        return [Decimal::fromFloat($lat, 6), Decimal::fromFloat($lon, 6)];
    }

    /**
     * The opening times, kept to the documented shape: `usual_days` by
     * weekday and `bank_holiday`, with times and the 24-hour flag.
     *
     * @param array<mixed> $value
     * @return array<string, mixed>|null
     */
    private static function openingHours(array $value): ?array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $entry = is_array($value['usual_days'] ?? null) ? ($value['usual_days'][$day] ?? null) : null;
            if (is_array($entry)) {
                $days[$day] = [
                    'open' => self::clock($entry['open'] ?? null),
                    'close' => self::clock($entry['close'] ?? null),
                    'is_24_hours' => ($entry['is_24_hours'] ?? false) === true,
                ];
            }
        }
        $holiday = is_array($value['bank_holiday'] ?? null) ? $value['bank_holiday'] : null;
        $hours = [];
        if ($days !== []) {
            $hours['usual_days'] = $days;
        }
        if ($holiday !== null) {
            $hours['bank_holiday'] = [
                'open' => self::clock($holiday['open_time'] ?? null),
                'close' => self::clock($holiday['close_time'] ?? null),
                'is_24_hours' => ($holiday['is_24_hours'] ?? false) === true,
            ];
        }

        return $hours === [] ? null : $hours;
    }

    private static function clock(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m) === 1
            ? $m[1] . ':' . $m[2]
            : null;
    }
}
