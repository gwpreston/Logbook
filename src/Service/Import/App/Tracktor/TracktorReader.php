<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Recognises a Tracktor export and reads it into typed rows (spec.md §7.13
 * *The Tracktor reader*, confirmed from real exports of Tracktor 2.1.0 and
 * 1.4.1). Numbers stay as the decimal text the JSON held and dates as
 * calendar dates; the import reads them with the units the mapping page
 * settles.
 *
 * Only the lists below are read. A notification provider's credentials,
 * Tracktor 1.x's `users` and `sessions`, and every config but the units and
 * currency are never looked at.
 */
final class TracktorReader
{
    public const string APP = 'tracktor';
    public const string VERSION = '1.0';
    /** Rows across every list, as for Fuelio (SectionSplitter::MAX_ROWS). */
    public const int MAX_ROWS = 20000;
    /** JSON nesting allowed; an export needs four. */
    public const int MAX_DEPTH = 64;

    /** The lists of `data` that are read; every other key is listed as not read. */
    private const array READ = [
        'vehicles', 'fuelLogs', 'maintenanceLogs', 'complianceDocuments', 'reminders', 'configs',
        'insurances', 'puccs',
    ];
    private const array ROW_LISTS = ['fuelLogs', 'maintenanceLogs', 'complianceDocuments', 'reminders', 'insurances', 'puccs'];
    private const array UNITS = ['liter', 'gallon', 'kilogram', 'pound'];

    /**
     * Whether $contents is a Tracktor export (encrypted or not): a JSON
     * object with `version` and `data`.
     */
    public static function detect(string $contents): bool
    {
        $text = self::text($contents);
        if (!str_starts_with($text, '{')) {
            return false;
        }
        try {
            $json = json_decode($text, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($json) && array_key_exists('version', $json) && array_key_exists('data', $json);
    }

    /**
     * @param DateTimeZone|null $zone the owner's zone, to read Tracktor's dates in (see date())
     * @throws TracktorRefused when the file is not one Logbook reads
     */
    public static function read(string $fileName, string $contents, ?DateTimeZone $zone = null): TracktorExport
    {
        try {
            $json = json_decode(self::text($contents), true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TracktorRefused(
                $e->getCode() === JSON_ERROR_DEPTH ? 'import_app.tracktor.too_deep' : 'import_app.tracktor.not_json',
            );
        }
        if (!is_array($json) || !array_key_exists('version', $json) || !array_key_exists('data', $json)) {
            throw new TracktorRefused('import_app.tracktor.not_json');
        }
        if (($json['encrypted'] ?? false) === true || is_string($json['data'])) {
            throw new TracktorRefused('import_app.tracktor.encrypted');
        }
        $version = is_scalar($json['version']) ? (string) $json['version'] : '';
        if ($version !== self::VERSION) {
            throw new TracktorRefused('import_app.tracktor.version', ['version' => mb_substr($version, 0, 20)]);
        }
        $data = $json['data'];
        if (!is_array($data) || !is_array($data['vehicles'] ?? null)) {
            throw new TracktorRefused('import_app.tracktor.not_json');
        }

        $rows = count($data['vehicles']);
        foreach (self::ROW_LISTS as $list) {
            $rows += is_array($data[$list] ?? null) ? count($data[$list]) : 0;
        }
        if ($rows > self::MAX_ROWS) {
            throw new TracktorRefused('import.error.too_many_rows', ['max' => self::MAX_ROWS]);
        }

        $unread = array_values(array_filter(
            array_map('strval', array_keys($data)),
            static fn (string $key): bool => !in_array($key, self::READ, true),
        ));

        $vehicleIds = [];
        foreach (self::list($data, 'vehicles') as $row) {
            $vehicleIds[self::string($row, 'id')] = true;
        }
        unset($vehicleIds['']);
        $orphans = 0;
        $grouped = [];
        foreach (self::ROW_LISTS as $list) {
            $grouped[$list] = self::byVehicle(self::list($data, $list), $vehicleIds, $orphans);
        }

        $vehicles = [];
        foreach (self::list($data, 'vehicles') as $index => $r) {
            $id = self::string($r, 'id');
            if ($id === '') {
                continue;
            }
            $year = self::number($r['year'] ?? null);
            /** @return list<array{line: int, row: array<string, mixed>}> */
            $rows = static fn (string $list): array => $grouped[$list][$id] ?? [];
            $vehicles[] = new TracktorVehicle(
                line: $index + 1,
                id: $id,
                make: self::string($r, 'make'),
                model: self::string($r, 'model'),
                year: $year !== null && preg_match('/^\d{4}$/', $year) === 1 ? (int) $year : null,
                plate: self::string($r, 'licensePlate'),
                vin: self::string($r, 'vin'),
                color: self::string($r, 'color'),
                odometer: self::number($r['odometer'] ?? null),
                image: self::file($r, 'image'),
                fuelType: self::string($r, 'fuelType') ?: 'petrol',
                vehicleType: array_key_exists('vehicleType', $r) ? (self::string($r, 'vehicleType') ?: 'car') : null,
                customFields: self::customFields($r['customFields'] ?? null),
                fills: array_map(fn (array $e): TracktorFill => self::fill($e, $zone), $rows('fuelLogs')),
                services: array_map(fn (array $e): TracktorServiceLog => self::service($e, $zone), $rows('maintenanceLogs')),
                documents: [
                    ...array_map(fn (array $e): TracktorDocument => self::document($e, $zone), $rows('complianceDocuments')),
                    ...array_map(fn (array $e): TracktorDocument => self::insurance($e, $zone), $rows('insurances')),
                    ...array_map(fn (array $e): TracktorDocument => self::pucc($e, $zone), $rows('puccs')),
                ],
                reminders: array_map(fn (array $e): TracktorReminder => self::reminder($e, $zone), $rows('reminders')),
            );
        }

        return new TracktorExport(
            fileName: $fileName,
            exportedAt: self::instant($json['timestamp'] ?? null),
            config: self::config($data['configs'] ?? null),
            vehicles: $vehicles,
            unread: $unread,
            legacy: array_key_exists('insurances', $data) || array_key_exists('puccs', $data),
            orphans: $orphans,
        );
    }

    /**
     * A JSON number (or numeric string) as plain decimal text: an integer as
     * written, a float as its shortest round-trip form, which is the text
     * Tracktor's JavaScript wrote (49.22 stays "49.22"). No arithmetic is
     * done on it. Null when empty or not a number.
     */
    public static function number(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            $text = (string) json_encode($value);
            if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
                return preg_replace('/\.0$/', '', $text) ?? $text;
            }
            // An exponent (1.0e-7): spelled out, trailing zeros dropped.
            $plain = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');

            return $plain === '-0' ? '0' : $plain;
        }
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value)) === 1) {
            return trim($value);
        }

        return null;
    }

    /**
     * The calendar date of one of Tracktor's dates. Tracktor stores the
     * date picked as the browser's local midnight in UTC
     * ("2026-03-29T23:00:00.000Z" is 30 March in London in summer). When
     * that instant is midnight in the owner's zone, its date there;
     * otherwise the nearest UTC midnight, which is right for every zone
     * from UTC−11 to UTC+12. A date alone ("2026-02-01") is taken as it is.
     * Null when it isn't a real date.
     */
    public static function date(mixed $value, ?DateTimeZone $zone = null): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})(T.*)?$/', trim($value), $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        if (($m[4] ?? '') === '') {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }
        try {
            $instant = new DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
        if ($zone !== null) {
            $local = $instant->setTimezone($zone);
            if ($local->format('H:i:s') === '00:00:00') {
                return $local->format('Y-m-d');
            }
        }

        return $instant->setTimezone(new DateTimeZone('UTC'))->modify('+12 hours')->format('Y-m-d');
    }

    /**
     * A list's rows by the vehicle they name, each with its position in the
     * list; rows naming no vehicle in the export are counted, not kept.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, true> $vehicleIds
     * @return array<string, list<array{line: int, row: array<string, mixed>}>>
     */
    private static function byVehicle(array $rows, array $vehicleIds, int &$orphans): array
    {
        $grouped = [];
        foreach ($rows as $index => $row) {
            $vehicleId = self::string($row, 'vehicleId');
            if (!isset($vehicleIds[$vehicleId])) {
                $orphans++;
                continue;
            }
            $grouped[$vehicleId][] = ['line' => $index + 1, 'row' => $row];
        }

        return $grouped;
    }

    /**
     * @param array{line: int, row: array<string, mixed>} $entry
     */
    private static function fill(array $entry, ?DateTimeZone $zone): TracktorFill
    {
        $r = $entry['row'];

        return new TracktorFill(
            line: $entry['line'],
            id: self::string($r, 'id'),
            date: self::date($r['date'] ?? null, $zone),
            odometer: self::number($r['odometer'] ?? null),
            amount: self::number($r['fuelAmount'] ?? null),
            rate: self::number($r['rate'] ?? null),
            cost: self::number($r['cost'] ?? null),
            full: !in_array($r['filled'] ?? true, [false, 0], true),
            missedLast: in_array($r['missedLast'] ?? false, [true, 1], true),
            notes: self::string($r, 'notes'),
            attachment: self::file($r),
        );
    }

    /**
     * @param array{line: int, row: array<string, mixed>} $entry
     */
    private static function service(array $entry, ?DateTimeZone $zone): TracktorServiceLog
    {
        $r = $entry['row'];

        return new TracktorServiceLog(
            line: $entry['line'],
            id: self::string($r, 'id'),
            date: self::date($r['date'] ?? null, $zone),
            odometer: self::number($r['odometer'] ?? null),
            serviceCenter: self::string($r, 'serviceCenter'),
            cost: self::number($r['cost'] ?? null),
            notes: self::string($r, 'notes'),
            attachment: self::file($r),
        );
    }

    /**
     * @param array{line: int, row: array<string, mixed>} $entry
     */
    private static function document(array $entry, ?DateTimeZone $zone): TracktorDocument
    {
        $r = $entry['row'];

        return new TracktorDocument(
            line: $entry['line'],
            id: self::string($r, 'id'),
            type: self::string($r, 'type'),
            otherLabel: self::string($r, 'otherLabel'),
            number: self::string($r, 'documentNumber'),
            issuer: self::string($r, 'issuer'),
            startDate: self::date($r['startDate'] ?? null, $zone),
            endDate: self::date($r['endDate'] ?? null, $zone),
            recurrence: self::string($r, 'recurrenceType') ?: 'none',
            interval: self::interval($r),
            cost: self::number($r['cost'] ?? null),
            notes: self::string($r, 'notes'),
            attachment: self::file($r),
        );
    }

    /**
     * Tracktor 1.x's `insurances` row.
     *
     * @param array{line: int, row: array<string, mixed>} $entry
     */
    private static function insurance(array $entry, ?DateTimeZone $zone): TracktorDocument
    {
        $r = $entry['row'];

        return new TracktorDocument(
            line: $entry['line'],
            id: self::string($r, 'id'),
            type: 'insurance',
            otherLabel: '',
            number: self::string($r, 'policyNumber'),
            issuer: self::string($r, 'provider'),
            startDate: self::date($r['startDate'] ?? null, $zone),
            endDate: self::date($r['endDate'] ?? null, $zone),
            recurrence: self::string($r, 'recurrenceType') ?: 'none',
            interval: self::interval($r),
            cost: self::number($r['cost'] ?? null),
            notes: self::string($r, 'notes'),
            attachment: self::file($r),
            list: 'insurances',
        );
    }

    /**
     * Tracktor 1.x's `puccs` row: a pollution certificate.
     *
     * @param array{line: int, row: array<string, mixed>} $entry
     */
    private static function pucc(array $entry, ?DateTimeZone $zone): TracktorDocument
    {
        $r = $entry['row'];

        return new TracktorDocument(
            line: $entry['line'],
            id: self::string($r, 'id'),
            type: 'emissions',
            otherLabel: '',
            number: self::string($r, 'certificateNumber'),
            issuer: self::string($r, 'testingCenter'),
            startDate: self::date($r['issueDate'] ?? null, $zone),
            endDate: self::date($r['expiryDate'] ?? null, $zone),
            recurrence: self::string($r, 'recurrenceType') ?: 'none',
            interval: self::interval($r),
            cost: self::number($r['cost'] ?? null),
            notes: self::string($r, 'notes'),
            attachment: self::file($r),
            list: 'puccs',
        );
    }

    /**
     * @param array{line: int, row: array<string, mixed>} $entry
     */
    private static function reminder(array $entry, ?DateTimeZone $zone): TracktorReminder
    {
        $r = $entry['row'];

        return new TracktorReminder(
            line: $entry['line'],
            id: self::string($r, 'id'),
            type: self::string($r, 'type') ?: 'custom',
            dueDate: self::date($r['dueDate'] ?? null, $zone),
            schedule: self::string($r, 'remindSchedule'),
            recurrence: self::string($r, 'recurrenceType') ?: 'none',
            interval: self::interval($r),
            note: self::string($r, 'note'),
            completed: in_array($r['isCompleted'] ?? false, [true, 1], true),
        );
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || self::date($value) === null) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Strip a byte-order mark and surrounding space.
     */
    private static function text(string $contents): string
    {
        return trim(str_starts_with($contents, "\u{FEFF}") ? substr($contents, 3) : $contents);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function list(array $data, string $key): array
    {
        $list = $data[$key] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $rows = [];
        foreach (array_values($list) as $row) {
            $rows[] = is_array($row) ? array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY) : [];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }

    /**
     * A file name from the uploads folder; only its base name is kept.
     *
     * @param array<string, mixed> $row
     */
    private static function file(array $row, string $key = 'attachment'): ?string
    {
        $name = basename(str_replace('\\', '/', self::string($row, $key)));

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function interval(array $row): int
    {
        $value = $row['recurrenceInterval'] ?? 1;

        return is_int($value) && $value > 0 ? min($value, 120) : 1;
    }

    /**
     * Tracktor keeps custom fields as a JSON object of strings, itself a
     * string.
     *
     * @return array<string, string>
     */
    private static function customFields(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            try {
                $value = json_decode($value, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }
        if (!is_array($value)) {
            return [];
        }
        $fields = [];
        foreach ($value as $key => $text) {
            if (is_scalar($text) && trim((string) $key) !== '' && trim((string) $text) !== '') {
                $fields[mb_substr(trim((string) $key), 0, 60)] = mb_substr(trim((string) $text), 0, 200);
            }
        }

        return $fields;
    }

    private static function config(mixed $configs): TracktorConfig
    {
        $values = [];
        if (is_array($configs)) {
            foreach ($configs as $key => $entry) {
                // A list of {key, value}; tolerate a plain map too.
                if (is_array($entry) && is_string($entry['key'] ?? null)) {
                    $values[$entry['key']] = is_scalar($entry['value'] ?? null) ? (string) $entry['value'] : '';
                } elseif (is_string($key) && is_scalar($entry)) {
                    $values[$key] = (string) $entry;
                }
            }
        }
        // Tracktor's own seed writes `litre`, its settings form `liter`.
        $pick = static function (string $key, array $allowed) use ($values): ?string {
            $value = str_replace('litre', 'liter', strtolower(trim($values[$key] ?? '')));

            return in_array($value, $allowed, true) ? $value : null;
        };
        $currency = strtoupper(trim($values['currency'] ?? ''));

        return new TracktorConfig(
            distance: $pick('unitOfDistance', ['kilometer', 'mile']),
            volume: $pick('unitOfVolume', ['liter', 'gallon']),
            lpg: $pick('unitOfLpg', self::UNITS),
            cng: $pick('unitOfCng', self::UNITS),
            currency: preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
        );
    }
}
