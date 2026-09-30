<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Trip\TaxYear;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\TripRepository;
use Logbook\Service\Vehicle\VehicleService;

/**
 * Builds claim reports (spec.md §7.23). The claimant's business trips are
 * always valued on every visible vehicle from the start of the tax year the
 * period starts in, so the threshold split is the same whichever vehicles
 * or range the report shows; rows are filtered only after valuing.
 */
final readonly class ClaimReportService
{
    public function __construct(
        private TripRepository $trips,
        private VehicleService $vehicles,
        private MileageRateService $rates,
        private TripSettingsStore $settings,
        private ClaimCalculator $calculator,
    ) {
    }

    public function build(User $user, ClaimFilter $filter, ?DateTimeImmutable $today = null): ClaimReport
    {
        $settings = $this->settings->for($user);
        $vehicles = [];
        foreach ($this->vehicles->listWith($user, VehicleAbility::View, true) as $vehicle) {
            $vehicles[$vehicle->id] = $vehicle;
        }
        $rateSets = $this->rates->forUser($user);
        $valuation = $this->value($user, $vehicles, $rateSets, $settings, $filter->from, $filter->until());

        $rows = [];
        $used = [];
        $included = [];
        foreach ($valuation->trips as $row) {
            $date = $row->trip->data->travelledOn;
            if ($date < $filter->from || $date > $filter->to || !$filter->includes($row->trip->vehicleId)) {
                continue;
            }
            $rows[] = $row;
            if ($row->rateSet !== null) {
                $used[$row->rateSet->id] = $row->rateSet;
            }
            $included[$row->trip->vehicleId] = $vehicles[$row->trip->vehicleId];
        }
        $sets = ClaimCalculator::byEffectiveDate(array_values($used));

        return new ClaimReport(
            filter: $filter,
            rows: $rows,
            totals: ClaimTotals::byCurrency($rows),
            unvalued: count(array_filter($rows, static fn (ValuedTrip $row): bool => !$row->isValued())),
            rateSets: $sets,
            vehicles: $vehicles,
            included: array_values($included),
            settings: $settings,
            thresholdLeft: $today === null ? null : $valuation->thresholdLeft($today),
        );
    }

    /**
     * The claimant's report for the tax year containing $today.
     *
     * @param list<int> $vehicleIds empty = every vehicle
     */
    public function thisYear(User $user, DateTimeImmutable $today, array $vehicleIds = []): ClaimReport
    {
        $year = TaxYear::containing($today, $this->settings->for($user)->taxYearStart);

        return $this->build($user, ClaimFilter::taxYear($year, $vehicleIds), $today);
    }

    public function taxYearOf(User $user, DateTimeImmutable $date): TaxYear
    {
        return TaxYear::containing($date, $this->settings->for($user)->taxYearStart);
    }

    /**
     * @param array<int, Vehicle> $vehicles
     * @param list<MileageRateSet> $rateSets
     */
    private function value(
        User $user,
        array $vehicles,
        array $rateSets,
        TripSettings $settings,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): ClaimValuation {
        $start = TaxYear::containing($from, $settings->taxYearStart)->start;
        $trips = $this->trips->listForVehiclesBetween(array_keys($vehicles), $start, $until, $user->id, true);
        $types = array_map(static fn (Vehicle $vehicle): VehicleType => $vehicle->data->type, $vehicles);

        return $this->calculator->value($trips, $types, $rateSets, $settings->taxYearStart);
    }
}
