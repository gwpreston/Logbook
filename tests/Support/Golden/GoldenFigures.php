<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Golden;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Trip\TaxYear;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\Forecast;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Notification\Digest\DigestSummary;
use Logbook\Service\Notification\Digest\LastMonth;
use Logbook\Service\Notification\Digest\LastMonthLine;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\OwnershipCost;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\TruePart;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\TripSettingsStore;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Currency;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\EconomyScale;
use Logbook\Support\Units\VolumeUnit;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The app's own figure for every row of tests/Fixtures/golden/expected.csv
 * (Phase 45.1), read through the services the pages use, as the row's
 * viewer: never through SQL, and without working money out again. What this
 * class does is only the picking of the right field of a service's result,
 * and the formatting the README asks for:
 *
 * - money to the currency's minor unit, rounded once, half away from zero;
 * - money per distance to one more place (per mile: per km × 1.609344);
 * - distance to the whole unit (miles: km ÷ 1.609344);
 * - economy to 2 places;
 * - "—" where the app shows no figure.
 */
final class GoldenFigures
{
    /** The app shows no figure. */
    public const string NONE = '—';
    private const string KM_PER_MILE = '1.609344';

    /** @var array<string, User> */
    private array $users = [];
    /** @var array<string, VehicleTrueCost|null> */
    private array $trueCosts = [];
    /** @var array<string, array<int|string, Report>> */
    private array $reports = [];
    /** @var array<string, Report> */
    private array $reportCache = [];
    /** @var array<string, Forecast> */
    private array $forecasts = [];
    /** @var array<string, LastMonth|null> */
    private array $digests = [];

    public function __construct(private readonly ContainerInterface $container, private readonly GoldenHousehold $household)
    {
    }

    /**
     * @param array<string, string> $row a row of expected.csv
     */
    public function actual(array $row): string
    {
        $figure = $row['figure'];
        $family = explode('.', $figure, 2)[0];

        return match ($family) {
            'ownership' => $this->ownership($row),
            'true_cost' => $this->trueCost($row),
            'reports' => $this->reportFigure($row),
            'finance' => $this->finance($row),
            'claim' => $this->claim($row),
            'coming_up' => $this->comingUp($row),
            'economy' => $this->economy($row),
            'digest' => $this->digest($row),
            'hidden' => $this->hidden($row),
            default => throw new RuntimeException("Unknown figure {$figure} (row {$row['id']})"),
        };
    }

    // Ownership --------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function ownership(array $row): string
    {
        $result = $this->vehicleTrueCost($row['viewer'], $row['vehicle']);
        if ($result === null) {
            return self::NONE;
        }
        $cost = $result->ownership;

        return match ($row['figure']) {
            'ownership.total' => $this->moneyOrNone($cost->total, $row),
            'ownership.running' => $this->moneyOrNone($cost->running, $row),
            'ownership.depreciation' => $this->moneyOrNone($cost->depreciationCost, $row),
            'ownership.payouts' => $this->moneyOrNone($cost->payouts ?? Money::zero($cost->currency), $row),
            'ownership.per_distance' => $this->perDistance($cost->perKm, $row),
            'ownership.per_month' => $this->moneyOrNone($cost->perMonth, $row),
            default => throw new RuntimeException("Unknown figure {$row['figure']} (row {$row['id']})"),
        };
    }

    // True cost --------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function trueCost(array $row): string
    {
        $result = $this->vehicleTrueCost($row['viewer'], $row['vehicle']);
        if (str_starts_with($row['figure'], 'true_cost.what_changed.')) {
            return $this->whatChanged($result, $row);
        }
        $period = $this->trueCostPeriod($result, $row['period']);
        if ($period === null) {
            return self::NONE;
        }
        $part = substr($row['figure'], strlen('true_cost.'));

        return match ($part) {
            'fuel', 'maintenance', 'compliance', 'other' => $this->moneyOrNone($period->amount(TruePart::from($part)), $row),
            'depreciation' => $this->moneyOrNone($period->depreciation, $row),
            'total' => $this->moneyOrNone($period->total(), $row),
            'payouts' => $this->moneyOrNone($period->payouts, $row),
            'per_distance' => $this->perDistance($period->perKm, $row),
            default => throw new RuntimeException("Unknown figure {$row['figure']} (row {$row['id']})"),
        };
    }

    /**
     * *What changed* as the page shows it (#411): a year against the one
     * before, the total change per distance and each part's (its
     * contributions add up to it, spec.md §7.35); the widget's last 12
     * months against the 12 before, in total. "—" where the page has none.
     *
     * @param array<string, string> $row
     */
    private function whatChanged(?VehicleTrueCost $result, array $row): string
    {
        $part = substr($row['figure'], strlen('true_cost.what_changed.'));
        if ($row['period'] === 'last_12_months_vs_previous') {
            if ($part !== 'total') {
                throw new RuntimeException("Only the total changes over 12 months (row {$row['id']})");
            }

            return $this->perDistance($result?->twelveMonthChange(), $row);
        }
        if (preg_match('/^(\d{4}) vs \d{4}$/', $row['period'], $m) !== 1) {
            throw new RuntimeException("Unknown What changed period {$row['period']} (row {$row['id']})");
        }
        $change = $result?->changeFor((int) $m[1]);
        if ($change === null) {
            return self::NONE;
        }
        if ($part === 'total') {
            return $this->perDistance($change->perKm, $row);
        }
        $truePart = TruePart::from($part);

        return $this->perDistance(
            Decimal::subtract($change->after->rate($truePart) ?? '0', $change->before->rate($truePart) ?? '0'),
            $row,
        );
    }

    private function trueCostPeriod(?VehicleTrueCost $result, string $period): ?TrueCost
    {
        if ($result === null) {
            return null;
        }
        if ($period === 'last_12_months') {
            return $result->lastTwelveMonths;
        }
        if (preg_match('/^\d{4}$/', $period) === 1) {
            foreach ($result->years as $year) {
                if ($year->period->year === (int) $period) {
                    return $year;
                }
            }

            return null;
        }

        throw new RuntimeException("Unknown true cost period {$period}");
    }

    private function vehicleTrueCost(string $viewer, string $vehicle): ?VehicleTrueCost
    {
        $key = $viewer . '|' . $vehicle;
        if (!array_key_exists($key, $this->trueCosts)) {
            $user = $this->user($viewer);
            $this->trueCosts[$key] = $this->get(TrueCostService::class)
                ->forVehicles($user, [$this->vehicle($user, $vehicle)], $this->today($user))[0] ?? null;
        }

        return $this->trueCosts[$key];
    }

    // Reports ----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function reportFigure(array $row): string
    {
        $figure = $row['figure'];
        $user = $this->user($row['viewer']);
        if ($figure === 'reports.fleet.total') {
            $report = $this->fleetReport($user, $row['period']);

            return $this->moneyOrNone($this->section($report, $row['currency'])?->total, $row);
        }
        $report = $this->vehicleReports($user, $row['vehicle'])[$row['period']] ?? null;
        if ($report === null) {
            throw new RuntimeException("No report for {$row['vehicle']} {$row['period']} (row {$row['id']})");
        }
        $section = $this->section($report, $row['currency']);
        if (str_starts_with($figure, 'reports.group.')) {
            $name = substr($figure, strlen('reports.group.'));
            if ($section === null) {
                return self::NONE;
            }

            return $this->money($name === 'total' ? $section->total : $section->group(CostGroup::from($name)), $row);
        }
        if (str_starts_with($figure, 'reports.category.')) {
            // No service totals spend by expense category: the ledger lines the Reports page lists carry it.
            $kind = 'expense.category.' . substr($figure, strlen('reports.category.'));
            $sum = Money::zero($row['currency']);
            foreach ($report->items as $item) {
                if ($item->kindKey === $kind && $item->currency() === $row['currency']) {
                    $sum = $sum->add($item->amount);
                }
            }

            return $this->money($sum, $row);
        }
        if ($figure === 'reports.cost_per_distance') {
            return $this->perDistance($section?->costPerKm, $row);
        }

        throw new RuntimeException("Unknown figure {$figure} (row {$row['id']})");
    }

    /**
     * One report over every vehicle the viewer sees the costs of (archived ones too), for a calendar year.
     */
    private function fleetReport(User $user, string $year): Report
    {
        $key = 'fleet|' . $user->id . '|' . $year;
        if (!isset($this->reportCache[$key])) {
            $this->reportCache[$key] = $this->get(ReportService::class)->build(
                $user,
                new ReportFilter($this->yearPeriod((int) $year, $this->today($user)), includeArchived: true),
            );
        }

        return $this->reportCache[$key];
    }

    /**
     * A vehicle's reports for each calendar year (to today for this year) and all time, keyed by the
     * row's period (`2024`, `all_time`).
     *
     * @return array<int|string, Report>
     */
    private function vehicleReports(User $user, string $vehicleKey): array
    {
        $key = $user->id . '|' . $vehicleKey;
        if (!isset($this->reports[$key])) {
            $today = $this->today($user);
            $vehicle = $this->vehicle($user, $vehicleKey);
            $filters = ['all_time' => new ReportFilter(
                ReportPeriod::preset(ReportRange::AllTime, $today),
                $vehicle->id,
                true,
            )];
            for ($year = 2023; $year <= (int) $today->format('Y'); $year++) {
                $filters[(string) $year] = new ReportFilter($this->yearPeriod($year, $today), $vehicle->id, true);
            }
            $built = $this->get(ReportService::class)->compare($user, [$vehicle], array_values($filters));
            $reports = [];
            foreach (array_keys($filters) as $i => $name) {
                $reports[$name] = $built[$i];
            }
            $this->reports[$key] = $reports;
        }

        return $this->reports[$key];
    }

    private function yearPeriod(int $year, DateTimeImmutable $today): ReportPeriod
    {
        $last = $year >= (int) $today->format('Y') ? $today : self::day("{$year}-12-31");

        return new ReportPeriod(ReportRange::Custom, self::day("{$year}-01-01"), $last);
    }

    private function section(Report $report, string $currency): ?CurrencyReport
    {
        foreach ($report->currencies as $section) {
            if ($section->currency === $currency) {
                return $section;
            }
        }

        return null;
    }

    // Finance ----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function finance(array $row): string
    {
        $user = $this->user($row['viewer']);
        $vehicle = $this->vehicle($user, $row['vehicle']);
        $service = $this->get(FinanceService::class);
        $agreements = $service->forVehicle($user, $vehicle);
        if (count($agreements) !== 1) {
            throw new RuntimeException("{$row['vehicle']} should have one finance agreement (row {$row['id']})");
        }
        $view = $service->view($user, $vehicle, $agreements[0]);
        $figures = $view->figures;

        return match ($row['figure']) {
            'finance.payments_made' => (string) $figures->schedule->made(),
            'finance.payments_remaining' => (string) $figures->schedule->remaining(),
            'finance.amount_paid' => $this->money($figures->paidSoFar, $row),
            'finance.interest_so_far' => $this->moneyOrNone($figures->costOfCredit?->soFar, $row),
            // The agreement page's "Cost of credit" row: the exact figure once ended, else total payable − cash price.
            'finance.cost_of_credit' => $this->moneyOrNone($this->costOfCredit($view), $row),
            'finance.settlement_estimate' => $this->moneyOrNone($figures->settlement?->amount, $row),
            'finance.equity' => $this->moneyOrNone($figures->equity?->amount, $row),
            default => throw new RuntimeException("Unknown figure {$row['figure']} (row {$row['id']})"),
        };
    }

    private function costOfCredit(AgreementView $view): ?Money
    {
        $credit = $view->figures->costOfCredit;

        return $credit === null ? null : ($credit->exact && $credit->soFar !== null ? $credit->soFar : $credit->total);
    }

    // Claims -----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function claim(array $row): string
    {
        $user = $this->user($row['viewer']);
        $vehicle = $this->vehicle($user, $row['vehicle']);
        if (preg_match('/^tax_year (\d{4})-\d{2}$/', $row['period'], $match) !== 1) {
            throw new RuntimeException("Unknown claim period {$row['period']}");
        }
        $settings = $this->get(TripSettingsStore::class)->for($user);
        $report = $this->get(ClaimReportService::class)->build(
            $user,
            ClaimFilter::taxYear(TaxYear::startingIn((int) $match[1], $settings->taxYearStart), [$vehicle->id]),
        );
        if ($report->unvalued !== 0) {
            return 'unvalued trips: ' . $report->unvalued;
        }

        return match ($row['figure']) {
            'claim.business_distance' => $this->distance($report->distanceKm(), $row),
            'claim.amount' => $report->totals === []
                ? self::NONE
                : $this->moneyOrNone($this->claimAmount($report->totals[0]->currency, $report->totals[0]->mileageAmount), $row),
            default => throw new RuntimeException("Unknown figure {$row['figure']} (row {$row['id']})"),
        };
    }

    private function claimAmount(string $currency, string $amount): Money
    {
        return Money::of($amount, $currency);
    }

    // Coming up --------------------------------------------------------------------------------------------------

    /**
     * Money, with "at_least " before it when an item has no known cost.
     *
     * @param array<string, string> $row
     */
    private function comingUp(array $row): string
    {
        $forecast = $this->forecast($row['viewer']);
        foreach ($forecast->totals as $totals) {
            if ($totals->currency !== $row['currency']) {
                continue;
            }
            $shown = match ($row['period']) {
                'next_3_months' => $totals->soon(),
                'next_12_months' => $totals,
                default => throw new RuntimeException("Unknown coming up period {$row['period']}"),
            };

            return ($shown->isAtLeast() ? 'at_least ' : '') . $this->money($shown->total(), $row);
        }

        return self::NONE;
    }

    private function forecast(string $viewer): Forecast
    {
        if (!isset($this->forecasts[$viewer])) {
            $user = $this->user($viewer);
            $this->forecasts[$viewer] = $this->get(ComingUp::class)
                ->forecast($user, $this->get(VehicleService::class)->listFleet($user));
        }

        return $this->forecasts[$viewer];
    }

    // Economy ----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function economy(array $row): string
    {
        $user = $this->user($row['viewer']);
        $vehicle = $this->vehicle($user, $row['vehicle']);
        $history = $this->get(FuelService::class)->history($vehicle);
        $kind = $row['unit'] === 'kWh/100km' ? EnergyKind::Electric : EnergyKind::Liquid;
        $scale = EconomyScale::of($kind, DistanceUnit::Kilometre, ConsumptionUnit::LitresPer100Km, VolumeUnit::Litre);

        if ($row['figure'] === 'economy.lifetime') {
            $summary = $history->summary($kind);
            if ($summary === null || !$summary->hasEconomy()) {
                return self::NONE;
            }

            return $this->economyValue($scale->value((float) $summary->measuredDistanceKm, (float) $summary->measuredVolume));
        }
        if (preg_match('/^segment ending (\S+ \S+)$/', $row['period'], $match) !== 1) {
            throw new RuntimeException("Unknown economy period {$row['period']}");
        }
        $users = [];
        foreach ($this->household->users as $name => $candidate) {
            $users[$candidate->id] = $candidate;
            unset($name);
        }
        foreach ($history->ofKind($kind) as $fill) {
            $author = $users[$fill->entry->createdBy ?? 0] ?? $user;
            $at = $fill->entry->data->filledAt->setTimezone($author->preferences->timeZone())->format('Y-m-d H:i');
            if ($at !== $match[1]) {
                continue;
            }
            $segment = $fill->segment;

            return $segment === null
                ? self::NONE
                : $this->economyValue($scale->value((float) $segment->distanceKm, (float) $segment->volume));
        }

        return self::NONE;
    }

    private function economyValue(?float $value): string
    {
        return $value === null ? self::NONE : Decimal::round(Decimal::fromFloat($value, 6), 2);
    }

    // Digest -----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function digest(array $row): string
    {
        $last = $this->lastMonth($row['viewer']);
        if ($last === null) {
            return self::NONE;
        }
        $figure = substr($row['figure'], strlen('digest.'));
        if ($figure === 'fleet.distance') {
            return $this->distance($last->fleetDistanceKm, $row);
        }
        if ($figure === 'fleet.spend') {
            return $this->moneyOrNone($last->fleetSpend[$row['currency']] ?? null, $row);
        }
        $line = $this->digestLine($last, $row['vehicle']);
        if ($line === null) {
            return self::NONE;
        }

        return match ($figure) {
            'distance' => $this->distance($line->distanceKm, $row),
            'distance_average' => $this->distance($line->distanceAverageKm, $row),
            'spend' => $this->moneyOrNone($line->costs?->spend, $row),
            'spend_average' => $this->moneyOrNone($line->costs?->average, $row),
            'cost_per_distance' => $this->perDistance($line->costs?->costPerKm, $row),
            'cost_per_distance_average' => $this->perDistance($line->costs?->costPerKmAverage, $row),
            default => throw new RuntimeException("Unknown figure {$row['figure']} (row {$row['id']})"),
        };
    }

    private function digestLine(LastMonth $last, string $vehicleKey): ?LastMonthLine
    {
        $id = $this->household->vehicle($vehicleKey)->id;
        foreach ($last->lines as $line) {
            if ($line->vehicle->id === $id) {
                return $line;
            }
        }

        return null;
    }

    /**
     * The monthly digest's *Last month* for a user, as the notifier builds it: the recipient vehicles
     * (archived ones too), read at the user's own today.
     */
    private function lastMonth(string $viewer): ?LastMonth
    {
        if (!array_key_exists($viewer, $this->digests)) {
            $user = $this->user($viewer);
            $ids = $this->get(VehicleAccess::class)->recipientVehicleIds($user, archivedToo: true);
            $this->digests[$viewer] = $this->get(DigestSummary::class)
                ->lastMonth($user, $this->get(VehicleRepository::class)->listByIds($ids), $this->today($user));
        }

        return $this->digests[$viewer];
    }

    // Hidden costs -----------------------------------------------------------------------------------------------

    /**
     * Sam has no costs on the Golf: "none" when every service that works out an amount gives him nothing
     * for it, else what leaked.
     *
     * @param array<string, string> $row
     */
    private function hidden(array $row): string
    {
        if ($row['figure'] !== 'hidden.golf_costs') {
            throw new RuntimeException("Unknown figure {$row['figure']} (row {$row['id']})");
        }
        $user = $this->user($row['viewer']);
        $golf = $this->vehicle($user, $row['vehicle']);
        $today = $this->today($user);
        $leaks = [];

        $report = $this->get(ReportService::class)->build(
            $user,
            new ReportFilter(ReportPeriod::preset(ReportRange::AllTime, $today), $golf->id, true),
        );
        // Asking for a vehicle the viewer may not see the costs of gives the fleet's report: Golf's lines must not be in it.
        $inReport = array_filter($report->items, static fn ($item): bool => $item->vehicle->id === $golf->id)
            + array_filter($report->vehicles, static fn (Vehicle $v): bool => $v->id === $golf->id);
        if ($inReport !== []) {
            $leaks[] = 'reports';
        }
        if ($this->get(TrueCostService::class)->forVehicles($user, [$golf], $today) !== []) {
            $leaks[] = 'true cost and ownership';
        }
        $forecast = $this->forecast($row['viewer']);
        foreach ($forecast->items() as $item) {
            if ($item->vehicle->id === $golf->id && $item->cost !== null) {
                $leaks[] = 'coming up item';
                break;
            }
        }
        if ($forecast->fuelFor($golf)?->total() !== null) {
            $leaks[] = 'coming up fuel';
        }
        $line = ($last = $this->lastMonth($row['viewer'])) === null ? null : $this->digestLine($last, $row['vehicle']);
        if ($line?->costs !== null) {
            $leaks[] = 'digest';
        }
        if ($this->get(FinanceService::class)->canSee($user, $golf)) {
            $leaks[] = 'finance';
        }
        if ($this->get(VehicleAccess::class)->can($user, VehicleAbility::ViewCosts, $golf)) {
            $leaks[] = 'access says costs are visible';
        }

        return $leaks === [] ? 'none' : 'leaks: ' . implode(', ', $leaks);
    }

    // Formatting -------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function moneyOrNone(?Money $money, array $row): string
    {
        return $money === null ? self::NONE : $this->money($money, $row);
    }

    /**
     * To the currency's minor unit, rounded once, half away from zero.
     *
     * @param array<string, string> $row
     */
    private function money(Money $money, array $row): string
    {
        if ($row['currency'] !== '' && $row['currency'] !== $money->currency) {
            return "currency {$money->currency}, not {$row['currency']}";
        }

        return $money->toDecimal(Currency::fractionDigits($money->currency));
    }

    /**
     * Money per kilometre (as the app keeps it) as the row's unit asks: per mile × 1.609344, to one more
     * place than the currency's minor unit.
     *
     * @param array<string, string> $row
     */
    private function perDistance(?string $perKm, array $row): string
    {
        if ($perKm === null) {
            return self::NONE;
        }
        $places = Currency::fractionDigits($row['currency']) + 1;
        $value = BigDecimal::of($perKm);
        if (str_ends_with($row['unit'], '/mi')) {
            $value = $value->multipliedBy(self::KM_PER_MILE);
        } elseif (!str_ends_with($row['unit'], '/km')) {
            throw new RuntimeException("Not a money per distance unit: {$row['unit']} (row {$row['id']})");
        }

        return (string) $value->toScale($places, RoundingMode::HalfUp);
    }

    /**
     * Kilometres in the row's unit (`mi` or `km`), to the whole unit.
     *
     * @param array<string, string> $row
     */
    private function distance(?string $km, array $row): string
    {
        if ($km === null) {
            return self::NONE;
        }
        $value = BigDecimal::of($km);

        return (string) match ($row['unit']) {
            'mi' => $value->dividedBy(self::KM_PER_MILE, 0, RoundingMode::HalfUp),
            'km' => $value->toScale(0, RoundingMode::HalfUp),
            default => throw new RuntimeException("Not a distance unit: {$row['unit']} (row {$row['id']})"),
        };
    }

    // Plumbing ---------------------------------------------------------------------------------------------------

    private function user(string $key): User
    {
        if (!isset($this->users[$key])) {
            $this->users[$key] = $this->get(UserDirectory::class)->find($this->household->user($key)->id)
                ?? throw new RuntimeException("No user {$key}");
        }

        return $this->users[$key];
    }

    private function vehicle(User $viewer, string $key): Vehicle
    {
        return $this->get(VehicleService::class)->get($viewer, $this->household->vehicle($key)->id);
    }

    private function today(User $user): DateTimeImmutable
    {
        return LocalTime::today($this->get(ClockInterface::class), $user->preferences->timeZone());
    }

    private static function day(string $date): DateTimeImmutable
    {
        return LocalTime::parseDate($date) ?? throw new RuntimeException("Not a date: {$date}");
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function get(string $id): object
    {
        $service = $this->container->get($id);
        if (!$service instanceof $id) {
            throw new RuntimeException("{$id} is not in the container");
        }

        return $service;
    }
}
