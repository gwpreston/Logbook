<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\TripRepository;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Support\Number\Decimal;

/**
 * The business and private split (spec.md §7.22): business from every
 * driver's business trips, the total from the mileage log's distance driven
 * (§7.7), private the difference. Logged private trips never change it.
 */
final readonly class MileageSplit
{
    public function __construct(
        private TripRepository $trips,
        private OdometerReadingRepository $readings,
        private TripService $access,
    ) {
    }

    /**
     * @param DateTimeImmutable $from first day, inclusive
     * @param DateTimeImmutable $to last day, inclusive
     */
    public function forVehicle(User $user, Vehicle $vehicle, DateTimeImmutable $from, DateTimeImmutable $to): SplitFigures
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        $businessKm = '0';
        $others = false;
        foreach ($this->trips->businessForVehicle($vehicle->id) as $trip) {
            $day = $trip->data->travelledOn->format('Y-m-d');
            if ($day < $first || $day > $last) {
                continue;
            }
            $businessKm = Decimal::add($businessKm, $trip->data->distanceKm);
            $others = $others || $trip->createdBy !== $user->id;
        }

        $total = PeriodDistance::km(
            $this->readings->listForVehicle($vehicle->id),
            new ReportPeriod(ReportRange::Custom, $from, $to),
            $user->preferences->timeZone(),
        );

        return self::split($businessKm, $total, $others && !$this->access->seesEveryone($user, $vehicle));
    }

    /**
     * Read the business trips of these vehicles once for the page.
     *
     * @param list<int> $vehicleIds
     */
    public function prime(array $vehicleIds): void
    {
        $this->trips->primeBusiness($vehicleIds);
    }

    public static function split(string $businessKm, ?string $totalKm, bool $totalOnly = false): SplitFigures
    {
        $private = $totalKm === null ? null : Decimal::subtract($totalKm, $businessKm);
        $exceeds = Decimal::compare($businessKm, '0') > 0
            && ($private === null || Decimal::compare($private, '0') < 0);

        return new SplitFigures(
            businessKm: $businessKm,
            totalKm: $totalKm,
            privateKm: $exceeds || $private === null ? null : $private,
            exceeds: $exceeds,
            totalOnly: $totalOnly,
        );
    }
}
