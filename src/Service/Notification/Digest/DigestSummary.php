<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Ai\Insights\AiInsight;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IssueRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Insights\AiInsightService;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Insights\Insight;
use Logbook\Service\Insights\InsightsService;
use Logbook\Service\Notification\DigestSection;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\PeriodDistance;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\UserDisplayScope;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The monthly briefing's figures (spec.md §7.11 *The monthly briefing*,
 * Phase 43), for one user's recipient vehicles: open issues, last month's
 * distance, spend and cost per distance against their averages, and the
 * insights. Every figure comes from the report code (ReportService,
 * PeriodDistance), so the digest never disagrees with the Reports page.
 * It never calls a model: AI insights are read from the kept set.
 */
final readonly class DigestSummary
{
    /** A month's cost per distance is compared only from this far (as spec.md §7.35). */
    public const string MIN_KM = '100';
    /** Fewer months than this to average over, and the comparison is left out. */
    public const int MIN_MONTHS = 3;
    private const int SCALE = 6;

    public function __construct(
        private ReportService $reports,
        private OdometerReadingRepository $readings,
        private IssueRepository $issues,
        private VehicleAccess $access,
        private VehicleService $vehicles,
        private FeatureToggles $features,
        private InsightsService $insights,
        private AiInsightService $ai,
        private UserDisplayScope $scope,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles the user's recipient vehicles
     * @param DateTimeImmutable $today the user's calendar date
     */
    public function build(
        User $user,
        array $vehicles,
        DateTimeImmutable $today,
        NotificationPreferences $preferences,
    ): DigestContent {
        $active = array_values(array_filter($vehicles, static fn (Vehicle $v): bool => !$v->isArchived()));

        return new DigestContent(
            $preferences->digestIncludes(DigestSection::Attention) ? $this->openIssues($active) : [],
            $preferences->digestIncludes(DigestSection::LastMonth) ? $this->lastMonth($user, $active, $today) : null,
            $preferences->digestIncludes(DigestSection::Insights) ? $this->insights($user, $active, $today) : [],
        );
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<OpenIssues>
     */
    private function openIssues(array $vehicles): array
    {
        if ($vehicles === [] || !$this->features->isEnabled(Feature::Issues)) {
            return [];
        }
        $counts = [];
        $ids = array_map(static fn (Vehicle $v): int => $v->id, $vehicles);
        foreach ($this->issues->listForVehicles($ids, [IssueStatus::Open]) as $issue) {
            $counts[$issue->vehicleId] = ($counts[$issue->vehicleId] ?? 0) + 1;
        }
        $lines = [];
        foreach ($vehicles as $vehicle) {
            if (isset($counts[$vehicle->id])) {
                $lines[] = new OpenIssues($vehicle, $counts[$vehicle->id]);
            }
        }

        return $lines;
    }

    /**
     * Last calendar month, per vehicle with a distance or (with `ViewCosts`)
     * a spend in it; null when none has.
     *
     * @param list<Vehicle> $vehicles
     */
    public function lastMonth(User $user, array $vehicles, DateTimeImmutable $today): ?LastMonth
    {
        if ($vehicles === [] || !$this->features->isEnabled(Feature::Reports)) {
            return null;
        }
        $zone = $user->preferences->timeZone();
        $firstOfMonth = $today->setDate((int) $today->format('Y'), (int) $today->format('n'), 1);
        $month = ReportPeriod::month(LocalTime::addMonths($firstOfMonth, -1));
        assert($month->from !== null);
        /** @var list<ReportPeriod> $window the 12 months before, oldest first */
        $window = [];
        for ($i = 12; $i >= 1; $i--) {
            $window[] = ReportPeriod::month(LocalTime::addMonths($month->from, -$i));
        }

        $lines = [];
        foreach ($vehicles as $vehicle) {
            $line = $this->line($user, $vehicle, $month, $window, $zone);
            if ($line !== null) {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return null;
        }

        $fleetKm = null;
        $fleetSpend = [];
        if (count($lines) > 1) {
            foreach ($lines as $line) {
                if ($line->distanceKm !== null) {
                    $fleetKm = Decimal::add($fleetKm ?? '0', $line->distanceKm);
                }
                if ($line->costs !== null) {
                    $currency = $line->costs->currency;
                    $fleetSpend[$currency] = ($fleetSpend[$currency] ?? Money::zero($currency))->add($line->costs->spend);
                }
            }
        }

        return new LastMonth($month->from, $lines, $fleetKm, $fleetSpend);
    }

    /**
     * @param list<ReportPeriod> $window
     */
    private function line(User $user, Vehicle $vehicle, ReportPeriod $month, array $window, DateTimeZone $zone): ?LastMonthLine
    {
        assert($month->from !== null && $window[0]->from !== null);
        $readings = $this->readings->listForVehicle($vehicle->id);
        $km = PeriodDistance::km($readings, $month, $zone);

        $measured = [];
        foreach ($window as $period) {
            $monthKm = PeriodDistance::km($readings, $period, $zone);
            if ($monthKm !== null) {
                $measured[] = $monthKm;
            }
        }
        $kmAverage = count($measured) >= self::MIN_MONTHS
            ? Decimal::divide(array_reduce($measured, Decimal::add(...), '0'), (string) count($measured), self::SCALE)
            : null;

        $costs = null;
        if ($this->access->can($user, VehicleAbility::ViewCosts, $vehicle)) {
            $firstReading = $readings === [] ? null : LocalTime::dateOf($readings[0]->recordedAt, $zone);
            $costs = $this->costs($user, $vehicle, $month, $window, $firstReading);
        }

        if ($km === null && ($costs === null || $costs->spend->isZero())) {
            return null;
        }

        return new LastMonthLine($vehicle, $km, $kmAverage, $costs);
    }

    /**
     * The month's spend and cost per distance as the Reports page counts
     * them, from one read of the ledger (ReportService::compare()).
     *
     * @param list<ReportPeriod> $window
     */
    private function costs(
        User $user,
        Vehicle $vehicle,
        ReportPeriod $month,
        array $window,
        ?DateTimeImmutable $firstReading,
    ): VehicleSpend {
        assert($month->from !== null && $window[0]->from !== null);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $twelve = new ReportPeriod(ReportRange::Custom, $window[0]->from, $window[11]->to);
        $filters = [new ReportFilter($month, $vehicle->id)];
        foreach ($window as $period) {
            $filters[] = new ReportFilter($period, $vehicle->id);
        }
        $filters[] = new ReportFilter($twelve, $vehicle->id);
        $filters[] = new ReportFilter(new ReportPeriod(ReportRange::AllTime, null, $month->to), $vehicle->id);
        $reports = $this->reports->compare($user, [$vehicle], $filters);
        $allTime = array_pop($reports);
        $year = array_pop($reports);
        $last = array_shift($reports);
        assert($allTime instanceof Report && $year instanceof Report && $last instanceof Report);

        // Months in use: from the first reading or ledger line (#364), at most 12.
        $first = $firstReading;
        if (!$allTime->isEmpty() && $allTime->period->from !== null && ($first === null || $allTime->period->from < $first)) {
            $first = $allTime->period->from;
        }
        $sum = Money::zero($currency);
        $months = 0;
        foreach ($reports as $i => $report) {
            $period = $window[$i];
            if ($first === null || $period->to < $first) {
                continue;
            }
            $months++;
            $sum = $sum->add($this->section($report, $currency)->total);
        }
        $average = $months >= self::MIN_MONTHS
            ? Money::of(Decimal::divide($sum->toDecimal(Money::SCALE), (string) $months, self::SCALE), $currency)
            : null;

        $section = $this->section($last, $currency);
        $largest = null;
        foreach ($last->items as $item) {
            if ($item->currency() === $currency && ($largest === null || $item->amount->micros > $largest->amount->micros)) {
                $largest = $item;
            }
        }
        $named = $largest instanceof CostItem && $largest->amount->micros * 2 > $section->total->micros ? $largest : null;

        return new VehicleSpend(
            $currency,
            $section->total,
            $average,
            self::perKm($section),
            self::perKm($this->section($year, $currency)),
            $named,
        );
    }

    private function section(Report $report, string $currency): CurrencyReport
    {
        foreach ($report->currencies as $section) {
            if ($section->currency === $currency) {
                return $section;
            }
        }

        return $report->currencies[0];
    }

    /**
     * The report's cost per distance, only from 100 km driven.
     */
    private static function perKm(CurrencyReport $section): ?string
    {
        if ($section->distanceKm === null || Decimal::compare($section->distanceKm, self::MIN_KM) < 0) {
            return null;
        }

        return $section->costPerKm;
    }

    /**
     * The computed insights (§7.8), all of them in order, then the AI ones
     * of the recent kept set (#362), as the Insights page shows them.
     *
     * @param list<Vehicle> $vehicles
     * @return list<DigestInsight>
     */
    private function insights(User $user, array $vehicles, DateTimeImmutable $today): array
    {
        if ($vehicles === []) {
            return [];
        }

        return $this->scope->run($user, function () use ($user, $vehicles, $today): array {
            $insights = array_map(fn (Insight $i): DigestInsight => new DigestInsight(
                $i->kind->value,
                DigestInsight::COMPUTED,
                $i->vehicleId === null ? [] : [$i->vehicleId],
                $this->translator->trans($i->title, $i->titleParams),
                $this->translator->trans($i->body, $i->bodyParams),
            ), $this->insights->forVehicles($user, $vehicles, true, $today));

            $ids = array_map(static fn (Vehicle $v): int => $v->id, $vehicles);
            foreach ($this->ai->recent($user)->insights ?? [] as $insight) {
                // Only those about the recipient vehicles (or none in particular).
                if ($insight->vehicles !== [] && array_intersect($insight->vehicles, $ids) === []) {
                    continue;
                }
                $insights[] = self::fromAi($insight);
            }

            return $insights;
        });
    }

    private static function fromAi(AiInsight $insight): DigestInsight
    {
        return new DigestInsight(
            $insight->topic->value,
            DigestInsight::AI,
            $insight->vehicles,
            $insight->title,
            $insight->body,
        );
    }
}
