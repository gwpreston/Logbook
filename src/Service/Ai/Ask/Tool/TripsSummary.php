<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Ask\Tool;

use DateTimeImmutable;
use Logbook\Domain\Ai\Ask\ToolResult;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\AskPeriod;
use Logbook\Service\Ai\Ask\AskTool;
use Logbook\Service\Ai\Ask\ToolArguments;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Service\Ai\Provider\ToolDefinition;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Trip\BusinessMileage;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\ClaimTotals;
use Logbook\Support\Number\Decimal;

/**
 * `trips_summary(period)`: the user's own business trips in a period,
 * their claim value at the approved rates per currency, and business
 * against private distance on the vehicles they drove (spec.md §7.26,
 * trips §7.22, claims §7.23). The tax year is the user's own.
 */
final readonly class TripsSummary implements AskTool
{
    /** "All time" starts here: no trip is older. */
    private const string EPOCH = '1990-01-01';

    public function __construct(
        private ToolKit $kit,
        private ClaimReportService $claims,
        private BusinessMileage $business,
        private FeatureToggles $features,
    ) {
    }

    public function name(): string
    {
        return 'trips_summary';
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->name(),
            'The user\'s business trips in a period: count, business distance, private distance, and the mileage '
            . 'claim value at the approved rates (with passengers and what the employer paid, where recorded). '
            . 'Defaults to the current tax year.',
            [
                'type' => 'object',
                'properties' => AskPeriod::SCHEMA,
                'additionalProperties' => false,
            ],
        );
    }

    public function isAvailable(User $user): bool
    {
        return $this->features->isEnabled(Feature::Trips);
    }

    public function run(User $user, ToolArguments $arguments): ToolResult
    {
        $period = $this->kit->period($user, $arguments, 'tax_year');
        $today = $this->kit->today($user);
        $filter = $period->preset === 'tax_year'
            ? ClaimFilter::taxYear($this->claims->taxYearOf($user, $today))
            : new ClaimFilter($period->from ?? new DateTimeImmutable(self::EPOCH), $period->to, null);
        $report = $this->claims->build($user, $filter, $today);
        $vehicles = array_values($report->vehicles);
        $split = $this->business->build($user, $vehicles, $filter->from, $filter->to, $today);

        $privateKm = $split->totalKm === null ? null : Decimal::subtract($split->totalKm, $split->businessKm);
        if ($privateKm !== null && Decimal::compare($privateKm, '0') < 0) {
            $privateKm = '0';
        }
        $claims = array_map(fn (ClaimTotals $totals): array => [
            'currency' => $totals->currency,
            'trips' => $totals->tripCount,
            'mileage' => $this->money($totals->mileageAmount, $totals->currency),
            'passengers' => $this->money($totals->passengerAmount, $totals->currency),
            'approved_total' => $this->money($totals->approvedAmount(), $totals->currency),
            'employer_paid' => $totals->employerAmount === null ? null : $this->money($totals->employerAmount, $totals->currency),
            'unpaid_difference' => $totals->difference() === null
                ? null
                : $this->money((string) $totals->difference(), $totals->currency),
        ], $report->totals);

        $figures = [$this->kit->format->distance($report->distanceKm())];
        foreach ($report->totals as $totals) {
            $figures[] = $this->kit->format->money($totals->approvedAmount(), $totals->currency);
        }
        $label = $filter->taxYear === null
            ? $this->kit->periodLabel($period)
            : $this->kit->t('ask.result.tax_year', ['year' => $filter->taxYear->label()]);

        return new ToolResult(
            [
                'period' => ['from' => $filter->from->format('Y-m-d'), 'to' => $filter->to->format('Y-m-d'), 'label' => $label],
                'business_trips' => count($report->rows),
                'business_distance' => $this->kit->distance($report->distanceKm()),
                'private_distance' => $this->kit->distance($privateKm),
                'total_distance' => $this->kit->distance($split->totalKm),
                'trips_without_a_rate' => $report->unvalued,
                'claim' => $claims,
                'note' => 'Only the user\'s own business trips are claimed. Private distance is the odometer distance '
                    . 'of the vehicles with business trips, less the business distance.',
            ],
            $this->kit->source([$this->kit->t('ask.tool.trips_summary'), $label]),
            $figures,
            $this->kit->link('/trips/claim', $filter->taxYear === null
                ? ['period' => 'custom', 'from' => $filter->from->format('Y-m-d'), 'to' => $filter->to->format('Y-m-d')]
                : ['year' => (string) $filter->taxYear->startYear()]),
            array_map(static fn (Vehicle $v): int => $v->id, $report->included),
        );
    }

    /**
     * @return array{amount: string, currency: string, display: string}
     */
    private function money(string $amount, string $currency): array
    {
        return [
            'amount' => Decimal::round($amount, 2),
            'currency' => $currency,
            'display' => $this->kit->format->money($amount, $currency),
        ];
    }
}
