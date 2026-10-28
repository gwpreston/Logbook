<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use Logbook\Service\Report\CostChange;
use Logbook\Service\Report\DistanceSpan;
use Logbook\Service\Report\PeriodDepreciation;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Service\Report\TrueCostWording;
use Logbook\Service\Report\TruePart;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Service\Vehicle\ValuePoint;
use Logbook\Service\Vehicle\ValuePointKind;
use Logbook\Support\AsOf;
use Logbook\Support\AsOfKind;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Working;
use Logbook\Support\WorkingInput;
use Logbook\Support\WorkingLink;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The working of true cost for a period, of the depreciation in it and of
 * *What changed* (spec.md §7.35, §8 *Show the working*), written from the
 * TrueCost and CostChange values the page shows, never worked out again.
 */
final readonly class TrueCostWorking
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
        private TrueCostWording $wording,
        private OwnershipWorking $ownership,
    ) {
    }

    /**
     * The period's parts, their sum and its cost per distance. Null for a
     * period with nothing logged and no depreciation.
     */
    public function period(VehicleTrueCost $vtc, TrueCost $cost, DateTimeImmutable $today): ?Working
    {
        $vehicleId = $cost->vehicle->id;
        $currency = $cost->currency;
        $inputs = [];
        $terms = [];
        foreach (TruePart::running() as $part) {
            $amount = $cost->amount($part);
            if ($amount === null || $amount->isZero()) {
                continue;
            }
            $value = $this->format->money($amount);
            $inputs[] = new WorkingInput($this->wording->part($part), $value, null, $this->partLink($part, $vehicleId));
            $terms[] = ($terms === [] ? '' : '+ ') . $this->wording->part($part) . ' ' . $value;
        }
        $sum = $terms === [] ? $this->format->money(Money::zero($currency)) : implode(' ', $terms);
        if (!$cost->payouts->isZero()) {
            $payouts = $this->format->money($cost->payouts);
            $inputs[] = new WorkingInput(
                $this->t('true_cost.payouts'),
                '−' . $payouts,
                null,
                new WorkingLink('incidents.index', ['id' => $vehicleId]),
            );
            $sum .= ' − ' . $this->t('true_cost.payouts') . ' ' . $payouts;
        }
        $notes = [];
        $steps = [];
        $depreciation = $this->depreciation($vtc, $cost, $today);
        if ($cost->depreciation !== null) {
            $loss = $this->format->money($cost->depreciation);
            $asOf = $this->depreciationAsOf($vtc, $cost);
            $inputs[] = new WorkingInput(
                $this->t('true_cost.part.depreciation'),
                $loss,
                $asOf,
                new WorkingLink('valuations.index', ['id' => $vehicleId]),
            );
            $gain = $cost->depreciation->isNegative();
            $sum .= ($gain ? ' − ' : ' + ') . $this->t('true_cost.part.depreciation') . ' '
                . $this->format->money($gain ? Money::zero($currency)->subtract($cost->depreciation) : $cost->depreciation);
        }
        if ($inputs === []) {
            return null;
        }
        $total = $cost->total();
        $steps[] = $this->t('working.true_cost.sum', ['sum' => $sum, 'total' => $this->format->money($total)]);

        $span = $cost->distanceSpan;
        if ($cost->perKm !== null && $cost->distanceKm !== null) {
            $inputs[] = new WorkingInput(
                $this->t('working.true_cost.distance'),
                $this->format->distance($cost->distanceKm),
                null,
                new WorkingLink('odometer.index', ['id' => $vehicleId]),
            );
            if ($span !== null) {
                $steps[] = $this->readings($span);
            }
            $depreciationRate = $cost->rate(TruePart::Depreciation);
            $runningRate = $depreciationRate === null ? $cost->perKm : Decimal::subtract($cost->perKm, $depreciationRate);
            $steps[] = $this->t('working.true_cost.rate_running', [
                'running' => $this->format->money($cost->running()),
                'distance' => $this->format->distance($cost->distanceKm),
                'rate' => $this->wording->rate($runningRate, $currency),
            ]);
            if ($depreciationRate !== null && $cost->depreciation !== null && $cost->depreciationKm !== null) {
                $steps[] = $this->t('working.true_cost.rate_depreciation', [
                    'amount' => $this->format->money($cost->depreciation),
                    'distance' => $this->format->distance($cost->depreciationKm),
                    'rate' => $this->wording->rate($depreciationRate, $currency),
                ]);
                $steps[] = $this->t('working.true_cost.rate_total', [
                    'perKm' => $this->wording->rate($cost->perKm, $currency),
                ]);
            } elseif ($cost->isRunningOnly()) {
                $notes[] = $this->t('true_cost.running_only');
            }
        } elseif ($cost->gap !== null) {
            $steps[] = $this->t($cost->gap->messageKey());
        }
        if ($depreciation !== null) {
            array_push($steps, ...$depreciation->steps);
            array_push($notes, ...$depreciation->notes);
        }
        // Cover outside ownership (spec.md §7.35, #564).
        // "so the years can differ from Since bought" only makes sense beside the yearly table (K153).
        $tail = $cost->period->range === TrueCostRange::Year ? '' : '_short';
        if ($cost->coverBefore !== null) {
            $notes[] = $this->t('working.true_cost.cover_before' . $tail, ['amount' => $this->format->money($cost->coverBefore)]);
        }
        if ($cost->coverAfter !== null) {
            $notes[] = $this->t('working.true_cost.cover_after' . $tail, ['amount' => $this->format->money($cost->coverAfter)]);
        }

        return new Working(
            $inputs,
            $steps,
            $notes,
            $cost->perKm === null ? $this->format->money($total) : $this->wording->rate($cost->perKm, $currency),
            $cost->perKm ?? $total->toDecimal(Money::SCALE),
        );
    }

    /**
     * The loss over the period, read off the owner's values. Null without
     * depreciation.
     */
    public function depreciation(VehicleTrueCost $vtc, TrueCost $cost, DateTimeImmutable $today): ?Working
    {
        if ($cost->depreciation === null) {
            return null;
        }
        $amount = $this->format->money($cost->depreciation);
        $detail = $cost->depreciationDetail;
        if ($detail === null) {
            // *Since bought* is the cost of ownership's own figure: the price less the latest value.
            return $cost->period->range === TrueCostRange::SinceBought
                ? $this->ownership->depreciation($vtc->ownership, $today)
                : null;
        }
        $notes = [$this->t('working.depreciation.not_assumed_period')];
        if ($detail->cutAtStart) {
            $notes[] = $this->t('working.depreciation.cut_start', ['date' => $this->format->date($detail->from)]);
        }
        if ($detail->cutAtEnd) {
            $notes[] = $this->t('working.depreciation.cut_end', ['date' => $this->format->date($detail->to)]);
        }

        return new Working(
            $this->depreciationInputs($cost, $detail, $today),
            [$this->periodSentence($detail, $amount)],
            $notes,
            $amount,
            $cost->depreciation->toDecimal(Money::SCALE),
        );
    }

    /**
     * *What changed*: the two periods side by side, part by part, and the
     * difference.
     */
    public function change(VehicleTrueCost $vtc, CostChange $change): Working
    {
        $currency = $vtc->currency;
        $before = $change->before;
        $after = $change->after;
        $inputs = [];
        foreach (TruePart::cases() as $part) {
            $was = $before->rate($part);
            $now = $after->rate($part);
            if ($was === null && $now === null) {
                continue;
            }
            $inputs[] = new WorkingInput(
                $this->wording->part($part),
                $this->t('working.true_cost.change_row', [
                    'before' => $this->wording->rate($was ?? '0', $currency),
                    'after' => $this->wording->rate($now ?? '0', $currency),
                    'difference' => $this->wording->signed(Decimal::subtract($now ?? '0', $was ?? '0'), $currency),
                ]),
            );
        }
        if (!$before->payouts->isZero() || !$after->payouts->isZero()) {
            $was = $before->payoutsPerKm ?? '0';
            $now = $after->payoutsPerKm ?? '0';
            $inputs[] = new WorkingInput(
                $this->t('true_cost.payouts'),
                $this->t('working.true_cost.change_row', [
                    'before' => $this->wording->rate($was, $currency),
                    'after' => $this->wording->rate($now, $currency),
                    'difference' => $this->wording->signed(Decimal::subtract($now, $was), $currency),
                ]),
            );
        }
        $steps = [$this->t('working.true_cost.change_sum', [
            'after' => $this->wording->rate($after->perKm, $currency),
            'before' => $this->wording->rate($before->perKm, $currency),
            'change' => $this->wording->signed($change->perKm, $currency),
            'now' => $this->wording->label($after),
            'was' => $this->wording->label($before),
        ])];
        foreach ($change->lines as $line) {
            $steps[] = $this->wording->sentence($line, $currency);
        }

        return new Working(
            $inputs,
            $steps,
            [$this->t('working.true_cost.change_note')],
            $this->wording->signed($change->perKm, $currency),
            $change->perKm,
        );
    }

    /**
     * @return list<WorkingInput>
     */
    private function depreciationInputs(TrueCost $cost, PeriodDepreciation $detail, DateTimeImmutable $today): array
    {
        $inputs = [];
        foreach ([...$detail->startPoints, ...$detail->stopPoints] as $point) {
            $key = $point->date->format('Y-m-d') . $point->kind->value . $point->amount;
            $valuation = $point->kind === ValuePointKind::Valuation;
            $inputs[$key] ??= new WorkingInput(
                $this->pointLabel($point),
                $this->format->money($point->amount, $cost->currency),
                $valuation ? AsOf::of(AsOfKind::Valuation, $point->date, $today) : null,
                new WorkingLink($valuation ? 'valuations.index' : 'vehicles.show', ['id' => $cost->vehicle->id]),
            );
        }

        return array_values($inputs);
    }

    private function periodSentence(PeriodDepreciation $detail, string $amount): string
    {
        $stopLabel = $detail->cutAtEnd
            ? $this->t('working.depreciation.value_at', ['date' => $this->format->date($detail->to)])
            : $this->t('working.depreciation.value_end', ['date' => $this->format->date($detail->to)]);

        return $this->t('working.depreciation.period', [
            'start' => $this->t('working.depreciation.value_on', ['date' => $this->format->date($detail->from)]),
            'startValue' => $this->format->money($detail->startValue),
            'startHow' => $this->how($detail->startPoints),
            'stop' => $stopLabel,
            'stopValue' => $this->format->money($detail->stopValue),
            'stopHow' => $this->how($detail->stopPoints),
            'amount' => $amount,
        ]);
    }

    /**
     * Where a value was read from: "your valuation of 3 Oct 2025, £14,900"
     * or "a straight line between … and …".
     *
     * @param list<ValuePoint> $points
     */
    private function how(array $points): string
    {
        return match (count($points)) {
            1 => $this->t('working.depreciation.how_point', ['point' => $this->pointText($points[0])]),
            2 => $this->t('working.depreciation.how_line', [
                'first' => $this->pointText($points[0]),
                'second' => $this->pointText($points[1]),
            ]),
            default => '',
        };
    }

    private function pointText(ValuePoint $point): string
    {
        return $this->t('working.depreciation.point.' . $point->kind->value, [
            'date' => $this->format->date($point->date),
            'amount' => $this->format->money($point->amount),
        ]);
    }

    private function pointLabel(ValuePoint $point): string
    {
        return $this->t('working.depreciation.label.' . $point->kind->value);
    }

    private function readings(DistanceSpan $span): string
    {
        return $this->t('working.true_cost.readings', [
            'distance' => $this->format->distance($span->km),
            'startOn' => $this->format->date($span->startOn),
            'startKm' => $this->format->distance($span->startKm),
            'endOn' => $this->format->date($span->endOn),
            'endKm' => $this->format->distance($span->endKm),
        ]);
    }

    private function depreciationAsOf(VehicleTrueCost $vtc, TrueCost $cost): ?AsOf
    {
        if ($cost->period->range === TrueCostRange::SinceBought) {
            return $vtc->ownership->asOf();
        }

        return null;
    }

    private function partLink(TruePart $part, int $vehicleId): WorkingLink
    {
        return new WorkingLink(match ($part) {
            TruePart::Fuel => 'fuel.index',
            TruePart::Maintenance => 'maintenance.index',
            TruePart::Documents => 'compliance.index',
            TruePart::Other => 'expenses.index',
            TruePart::Depreciation => 'valuations.index',
        }, ['id' => $vehicleId]);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }
}
