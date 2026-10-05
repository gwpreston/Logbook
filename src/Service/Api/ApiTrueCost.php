<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Report\ChangeLine;
use Logbook\Service\Report\CostChange;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\TrueCostWording;
use Logbook\Service\Report\TruePart;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * GET /api/v1/vehicles/{id}/true-cost (spec.md §7.20, §7.35): the chosen
 * period's parts per kilometre, each calendar year and what changed, the
 * same figures as the pages. Money in the vehicle's currency with 3
 * places, rates per km with 6 (so the parts add up exactly), `display`
 * text in the key owner's units.
 */
final readonly class ApiTrueCost
{
    public const int SCALE = 6;

    public function __construct(
        private TrueCostService $trueCosts,
        private TrueCostWording $wording,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, Vehicle $vehicle, TrueCostRange $range): array
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $vtc = $this->trueCosts->forVehicle($user, $vehicle, $today);
        if ($vtc === null) {
            return ['vehicle_id' => $vehicle->id, 'period' => $range->value, 'true_cost' => null, 'years' => []];
        }
        $cost = $vtc->period($range);
        $change = $range === TrueCostRange::TwelveMonths ? $vtc->twelveMonthChange() : null;

        return [
            'vehicle_id' => $vehicle->id,
            'currency' => $vtc->currency,
            'period' => $range->value,
            'true_cost' => $cost === null ? null : $this->cost($cost),
            'change_against_previous_12_months' => Serializer::dec($change, self::SCALE),
            'years' => array_map(fn (TrueCost $year): array => [
                'year' => $year->period->year,
                'label' => $this->wording->label($year),
                'comparable' => $year->isComparable(),
                ...$this->cost($year),
                'what_changed' => $year->period->year === null || $vtc->changeFor($year->period->year) === null
                    ? null
                    : $this->change($vtc->changeFor($year->period->year)),
            ], $vtc->years),
            'display' => [
                'per_distance' => $cost?->perKm === null ? null : $this->wording->rate($cost->perKm, $vtc->currency),
                'change_against_previous_12_months' => $change === null ? null : $this->wording->arrow($change, $vtc->currency),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cost(TrueCost $cost): array
    {
        $parts = [];
        foreach (TruePart::cases() as $part) {
            $amount = $cost->amount($part);
            $parts[$part->value] = [
                'amount' => $amount?->toDecimal(3),
                'per_distance' => Serializer::dec($cost->rate($part), self::SCALE),
            ];
        }

        return [
            'from' => Serializer::date($cost->period->from),
            'to' => Serializer::date($cost->period->to),
            'partial' => $cost->period->isPartial(),
            'distance' => Serializer::dec($cost->distanceKm, Serializer::QUANTITY_SCALE),
            'per_distance' => Serializer::dec($cost->perKm, self::SCALE),
            'why_not' => $cost->gap?->value,
            'running_costs_only' => $cost->isRunningOnly(),
            'depreciation_to' => Serializer::date($cost->depreciationTo),
            'parts' => $parts,
            'insurance_payouts' => $cost->payouts->isZero() ? null : [
                'amount' => $cost->payouts->toDecimal(3),
                'per_distance' => Serializer::dec($cost->payoutsPerKm, self::SCALE),
            ],
            'total' => $cost->total()->toDecimal(3),
            'breakdown_display' => $this->wording->breakdown($cost),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function change(?CostChange $change): ?array
    {
        if ($change === null) {
            return null;
        }
        $currency = $change->after->currency;

        return [
            'against' => $change->before->period->year,
            'per_distance' => Serializer::dec($change->perKm, self::SCALE),
            'display' => $this->wording->signed($change->perKm, $currency),
            'lines' => array_map(fn (ChangeLine $line): array => [
                'cause' => $line->cause->value,
                'part' => $line->part?->value,
                'energy' => $line->energy?->value,
                'per_distance' => Serializer::dec($line->perKm, self::SCALE),
                'sentence' => $this->wording->sentence($line, $currency),
            ], $change->lines),
        ];
    }
}
