<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Date\LocalTime;

/**
 * The reading a modification, its revert, or a fitting or removal made
 * without a record owns (spec.md §7.39 *Odometer*, #421): at local noon on
 * its date, written, moved and removed with it. None is written where the
 * vehicle already has a reading that day at the same odometer, as for an
 * issue (#319).
 */
final readonly class DatedReading
{
    private const string READING_TIME = 'T12:00';

    public function __construct(private OdometerService $odometer)
    {
    }

    public function record(
        Vehicle $vehicle,
        OdometerSource $source,
        int $id,
        ?string $km,
        DateTimeImmutable $on,
        DateTimeZone $zone,
    ): void {
        $day = $on->format('Y-m-d');
        $at = LocalTime::toUtc($day . self::READING_TIME, $zone)
            ?? DateTimeImmutable::createFromInterface($on);
        $from = LocalTime::toUtc($day . 'T00:00', $zone);
        $until = LocalTime::toUtc((new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d') . 'T00:00', $zone);
        if (
            $km !== null && $from !== null && $until !== null
            && $this->odometer->hasOtherReadingAt($vehicle, $km, $from, $until, $source, $id)
        ) {
            $km = null;
        }
        $this->odometer->recordForEntry($vehicle, $source, $id, $km, $at);
    }

    public function forget(Vehicle $vehicle, OdometerSource $source, int $id): void
    {
        $this->odometer->forgetEntry($vehicle, $source, $id);
    }
}
