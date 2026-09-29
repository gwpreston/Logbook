<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Support\Date\LocalTime;

/**
 * The add form's current odometer and its *As of* date (spec.md §7.1): the
 * vehicle's first manual reading.
 */
final readonly class StartingReading
{
    private const string READING_TIME = 'T12:00';

    public function __construct(
        /** Canonical km. */
        public string $km,
        /** The calendar date it was read (midnight UTC), or null for today. */
        public ?DateTimeImmutable $on = null,
    ) {
    }

    /**
     * When the reading is recorded (UTC): the moment of saving when it was
     * read today (or no date was given), else local noon on its date, like
     * the other date-only readings (spec.md §7.2), so it stays on that day
     * in the owner's time zone whichever side of UTC they are.
     */
    public function recordedAt(DateTimeImmutable $now, DateTimeZone $zone): DateTimeImmutable
    {
        if ($this->on === null || $this->on->format('Y-m-d') === LocalTime::fromUtc($now, $zone)->format('Y-m-d')) {
            return $now;
        }
        $at = LocalTime::toUtc($this->on->format('Y-m-d') . self::READING_TIME, $zone);
        assert($at instanceof DateTimeImmutable);

        return $at;
    }
}
