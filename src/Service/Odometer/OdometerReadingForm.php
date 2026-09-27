<?php

declare(strict_types=1);

namespace Logbook\Service\Odometer;

use DateTimeImmutable;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit odometer reading form ↔ OdometerReadingData. The reading is typed
 * in the user's distance unit and stored in km; the date and time are typed
 * in the user's time zone and stored in UTC.
 */
final class OdometerReadingForm
{
    public const int KM_SCALE = 3;
    /** Digits before the point for a typed reading (99,999,999). */
    public const int MAX_WHOLE_DIGITS = 8;
    public const string LOCAL_FORMAT = 'Y-m-d\TH:i';

    /**
     * @return array<string, string>
     */
    public static function values(OdometerReading $reading, DisplayPreferences $preferences): array
    {
        return [
            'recorded_at' => LocalTime::fromUtc($reading->recordedAt, $preferences->timeZone())->format(self::LOCAL_FORMAT),
            'reading' => self::distanceForDisplay($reading->readingKm, $preferences),
            'note' => $reading->note ?? '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $now, DisplayPreferences $preferences): array
    {
        return ['recorded_at' => LocalTime::fromUtc($now, $preferences->timeZone())->format(self::LOCAL_FORMAT)];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, DisplayPreferences $preferences): OdometerReadingData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);

        $recordedAt = $validator->dateTime('recorded_at', $preferences->timeZone(), true);
        $reading = $validator->decimal('reading', true, self::KM_SCALE, '0', null, self::MAX_WHOLE_DIGITS);
        $note = $validator->string('note', false, 255);

        if (!$validator->errors()->isEmpty() || $recordedAt === null || $reading === null) {
            return $validator->errors();
        }

        return new OdometerReadingData(
            $preferences->distanceUnit->toKmDecimal($reading, self::KM_SCALE),
            $recordedAt,
            $note,
        );
    }

    /**
     * Kilometres → the user's unit, for pre-filling an input: exact enough
     * that a value typed with up to 3 decimals comes back unchanged.
     */
    public static function distanceForDisplay(string $km, DisplayPreferences $preferences): string
    {
        return Decimal::trim($preferences->distanceUnit->fromKmDecimal($km, self::KM_SCALE));
    }
}
