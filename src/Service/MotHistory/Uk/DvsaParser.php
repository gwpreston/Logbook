<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory\Uk;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Logbook\Domain\MotHistory\MotDataSource;
use Logbook\Domain\MotHistory\MotDefectRecord;
use Logbook\Domain\MotHistory\MotDefectType;
use Logbook\Domain\MotHistory\MotTestRecord;
use Logbook\Domain\MotHistory\MotTestResult;
use Logbook\Domain\MotHistory\MotVehicleRecord;
use Logbook\Domain\MotHistory\OdometerState;
use Logbook\Domain\MotHistory\RecallState;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * Reads DVSA's answer for one vehicle (spec.md §4, §7.38): the fields of
 * DVSA's OpenAPI specification, checked one by one; anything else is
 * ignored.
 *
 * - A test with no completed date is skipped and counted; one with no
 *   number is keyed by its source and completed time (#334).
 * - A null or unknown defect type is `non_specific` (#333).
 * - The odometer is converted to kilometres only when it was read.
 * - Tests come back newest first, whatever order DVSA sent.
 */
final class DvsaParser
{
    private const int TEXT_LIMIT = 2000;
    private const int FIELD_LIMIT = 100;

    /**
     * @param array<mixed> $data a decoded `200` answer
     * @return MotVehicleRecord|null null when it is not a vehicle
     */
    public static function vehicle(array $data): ?MotVehicleRecord
    {
        if (array_is_list($data) || !array_key_exists('hasOutstandingRecall', $data)) {
            return null;
        }
        $tests = [];
        $undated = 0;
        foreach (is_array($data['motTests'] ?? null) ? $data['motTests'] : [] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $test = self::test($raw);
            if ($test === null) {
                $undated++;
                continue;
            }
            $tests[$test->number] = $test;
        }
        $tests = array_values($tests);
        usort($tests, static fn (MotTestRecord $a, MotTestRecord $b): int => $b->completedAt <=> $a->completedAt);

        return new MotVehicleRecord(
            self::text($data['registration'] ?? null, 20),
            self::text($data['make'] ?? null),
            self::text($data['model'] ?? null),
            self::text($data['fuelType'] ?? null),
            self::text($data['primaryColour'] ?? null),
            self::date($data['firstUsedDate'] ?? null),
            self::date($data['registrationDate'] ?? null),
            self::date($data['motTestDueDate'] ?? null),
            RecallState::fromDvsa($data['hasOutstandingRecall']),
            $tests,
            $undated,
        );
    }

    /**
     * @param array<mixed> $raw
     */
    private static function test(array $raw): ?MotTestRecord
    {
        $completed = self::instant($raw['completedDate'] ?? null);
        $result = match (is_string($raw['testResult'] ?? null) ? strtoupper(trim($raw['testResult'])) : '') {
            'PASSED' => MotTestResult::Passed,
            'FAILED' => MotTestResult::Failed,
            default => null,
        };
        if ($completed === null || $result === null) {
            return null;
        }
        $source = MotDataSource::fromDvsa($raw['dataSource'] ?? null);
        $number = self::text($raw['motTestNumber'] ?? null, 40);
        if ($number === null || preg_match('/^[A-Za-z0-9\/-]{1,40}$/', $number) !== 1) {
            $number = $source->value . ':' . $completed->format('Y-m-d\TH:i:s\Z');
        }

        $state = OdometerState::fromDvsa($raw['odometerResultType'] ?? null);
        $unit = match (is_string($raw['odometerUnit'] ?? null) ? strtoupper(trim($raw['odometerUnit'])) : '') {
            'MI' => DistanceUnit::Mile,
            'KM' => DistanceUnit::Kilometre,
            default => null,
        };
        $value = is_string($raw['odometerValue'] ?? null) || is_int($raw['odometerValue'] ?? null)
            ? trim((string) $raw['odometerValue'])
            : '';
        $km = null;
        if ($state === OdometerState::Read && $unit !== null && preg_match('/^\d{1,7}$/', $value) === 1) {
            $km = $unit->toKmDecimal(Decimal::round($value, 0), 3);
        } elseif ($state === OdometerState::Read) {
            // Read, but no usable figure: nothing to log.
            $state = OdometerState::None;
        }

        $defects = [];
        foreach (is_array($raw['defects'] ?? null) ? $raw['defects'] : [] as $defect) {
            if (!is_array($defect)) {
                continue;
            }
            $text = self::text($defect['text'] ?? null, self::TEXT_LIMIT);
            if ($text === null) {
                continue;
            }
            $type = MotDefectType::fromDvsa($defect['type'] ?? null);
            $defects[] = new MotDefectRecord(
                $type,
                $text,
                ($defect['dangerous'] ?? false) === true || $type === MotDefectType::Dangerous,
            );
        }

        return new MotTestRecord(
            $number,
            $completed,
            $result,
            self::date($raw['expiryDate'] ?? null),
            $km,
            $km === null ? null : $unit,
            $state,
            self::text($raw['registrationAtTimeOfTest'] ?? null, 20),
            $source,
            $defects,
        );
    }

    private static function text(mixed $value, int $limit = self::FIELD_LIMIT): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return null;
        }

        return mb_substr($text, 0, $limit);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone('UTC'));

        return $date === false || $date->format('Y-m-d') !== trim($value) ? null : $date;
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            // Some older records carry a date only: noon UTC keeps it on its day.
            $date = self::date($value);

            return $date?->setTime(12, 0);
        }
        try {
            // A time with no zone is UTC, as DVSA's examples are.
            $instant = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        // Whole seconds, so a refresh compares equal to what is stored.
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }
}
