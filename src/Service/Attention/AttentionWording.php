<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Forecast\ForecastSource;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Number\Decimal;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a *Needs attention* item is put into words (spec.md §7.24), in the
 * current locale and units: the overview card, the dashboard widget and the
 * digest all say the same thing. Overdue work keeps each source's own
 * title (ForecastWording) and the reminders' "overdue" wording.
 */
final readonly class AttentionWording
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $formatter,
        private ForecastWording $forecast,
    ) {
    }

    /**
     * "Now" or "Check": always shown as text.
     */
    public function label(AttentionItem $item): string
    {
        return $this->translator->trans('attention.severity.' . $item->severity()->value);
    }

    public function title(AttentionItem $item): string
    {
        return match ($item->kind) {
            AttentionKind::Overdue => $item->forecast === null ? '' : $this->forecast->title($item->forecast),
            AttentionKind::Reading => $item->motPair !== null && ($item->warning->type ?? null) === 'backwards'
                ? $this->motPairTitle($item)
                : $this->translator->trans('attention.reading.' . ($item->warning->type ?? 'backwards'), [
                    'date' => $this->formatter->instantDate($item->reading?->recordedAt),
                    'odometer' => $this->formatter->distance($item->reading?->readingKm),
                    'distance' => $this->formatter->distance(ltrim($item->warning->distanceKm ?? '0', '-')),
                ]),
            AttentionKind::Economy => $this->translator->trans('attention.economy.title', ['count' => $item->count]),
            AttentionKind::MileageStale => $item->latest === null
                ? $this->translator->trans('attention.mileage.none')
                : $this->translator->trans('attention.mileage.title', [
                    'date' => $this->formatter->instantDate($item->latest->recordedAt),
                ]),
            AttentionKind::TripsExceed => $this->translator->trans('attention.trips.title'),
            AttentionKind::ValuationStale => $this->translator->trans('attention.valuation.title', [
                'months' => $item->months ?? 0,
            ]),
            AttentionKind::DriftLiquid, AttentionKind::DriftElectric, AttentionKind::DriftGas => $this->driftTitle($item->drift),
            AttentionKind::FuelPrice => $this->priceTitle($item->price, $item->currency ?? ''),
            AttentionKind::MaintenanceCost => $this->costTitle($item->cost, $item->currency ?? ''),
            AttentionKind::StalledClaim => $this->translator->trans('attention.claim.title', [
                'has_number' => ($item->incident->claimNumber ?? null) === null ? 'no' : 'yes',
                'number' => $item->incident->claimNumber ?? '',
                'has_insurer' => ($item->incident->insurer ?? null) === null ? 'no' : 'yes',
                'insurer' => $item->incident->insurer ?? '',
                'days' => $item->days ?? 0,
            ]),
            AttentionKind::FinanceMissed => $this->translator->trans('attention.finance.missed', [
                'date' => $this->formatter->date($item->finance?->dueOn),
            ]),
            AttentionKind::FinanceMileage => $this->mileageTitle($item->finance),
            // The owner's words, never a cause (spec.md §7.37).
            AttentionKind::IssueOpen, AttentionKind::IssueLookAgain => $item->issue->data->title ?? '',
            AttentionKind::MotRecall => $this->translator->trans('attention.mot_recall.title', [
                'vehicle' => $item->vehicle->data->registration ?? $item->vehicle->name(),
            ]),
        };
    }

    /**
     * "Your reading on 2 Mar 2026 (41,200 mi) is lower than the MOT on 14 Feb
     * 2026 (43,950 mi)" (spec.md §7.38 *Mileage*): which reading is whose.
     */
    private function motPairTitle(AttentionItem $item): string
    {
        $flagged = $item->reading;
        $previous = $item->warning?->previous;
        [$yours, $mot] = $item->motPair === 'before' ? [$previous, $flagged] : [$flagged, $previous];

        $key = $item->motPair === 'before' ? 'attention.reading.mot_before' : 'attention.reading.mot_after';

        return $this->translator->trans($key, [
            'date' => $this->formatter->instantDate($yours?->recordedAt),
            'odometer' => $this->formatter->distance($yours?->readingKm),
            'mot_date' => $this->formatter->instantDate($mot?->recordedAt),
            'mot_odometer' => $this->formatter->distance($mot?->readingKm),
        ]);
    }

    /**
     * "Heading for about 1,200 mi over your allowance: about £108", in the
     * agreement's unit (spec.md §7.24 item 11).
     */
    private function mileageTitle(?FinanceFinding $finding): string
    {
        $mileage = $finding?->mileage;
        if ($mileage === null) {
            return '';
        }
        $excess = $this->formatter->aboutDistance($mileage->excessKm, $mileage->unit);
        $charge = $mileage->excessCharge;

        return $charge === null
            ? $this->translator->trans('attention.finance.mileage_no_charge', ['excess' => $excess])
            : $this->translator->trans('attention.finance.mileage', [
                'excess' => $excess,
                'charge' => $this->formatter->money($charge, null, 0),
            ]);
    }

    /**
     * The line under the title: how overdue, or why it matters.
     */
    public function detail(AttentionItem $item): string
    {
        return match ($item->kind) {
            AttentionKind::Overdue => $item->forecast === null ? '' : $this->overdue($item->forecast, $item->days),
            AttentionKind::Reading => $this->translator->trans(
                'attention.reading.detail.' . ($item->warning->type ?? 'backwards'),
                [
                    'previous' => $this->formatter->distance($item->warning?->previous->readingKm),
                    'date' => $this->formatter->instantDate($item->warning?->previous->recordedAt),
                ],
            ),
            AttentionKind::Economy => $this->translator->trans('attention.economy.detail'),
            AttentionKind::MileageStale => $this->translator->trans('attention.mileage.detail'),
            AttentionKind::TripsExceed => $this->translator->trans('attention.trips.detail'),
            AttentionKind::ValuationStale => $this->translator->trans('attention.valuation.detail', [
                'date' => $this->formatter->date($item->valuedOn),
            ]),
            AttentionKind::DriftLiquid, AttentionKind::DriftElectric, AttentionKind::DriftGas
                => $item->drift === null || $item->drift->seasonChecked
                    ? ''
                    : $this->translator->trans('attention.drift.season'),
            AttentionKind::FuelPrice => $this->translator->trans(
                ($item->price->digitSlip ?? false) ? 'attention.price.digit' : 'attention.price.detail',
            ),
            AttentionKind::MaintenanceCost => $this->translator->trans(
                ($item->cost->digitSlip ?? false) ? 'attention.cost.digit' : 'attention.cost.detail',
            ),
            AttentionKind::StalledClaim => $this->translator->trans('attention.claim.detail', [
                'type' => $item->incident === null ? '' : $this->translator->trans($item->incident->type->labelKey()),
                'date' => $this->formatter->date($item->incident?->occurredOn),
            ]),
            AttentionKind::FinanceMissed => $this->translator->trans('attention.finance.missed_detail', [
                'agreement' => $this->agreementName($item->finance),
            ]),
            AttentionKind::FinanceMileage => $this->mileageDetail($item->finance),
            AttentionKind::IssueOpen => $this->safety($item) . $this->translator->trans('attention.issue.noticed', [
                'date' => $this->formatter->date($item->issue?->data->noticedOn),
            ] + self::ago($item->days ?? 0)),
            AttentionKind::IssueLookAgain => $this->safety($item) . $this->translator->trans('attention.issue.watching_since', [
                'date' => $this->formatter->date($item->since),
            ]),
            AttentionKind::MotRecall => $this->translator->trans('attention.mot_recall.detail'),
        };
    }

    /**
     * "Affects safety · " before a safety issue's detail: in words, never colour alone.
     */
    private function safety(AttentionItem $item): string
    {
        return ($item->issue->data->affectsSafety ?? false) ? $this->translator->trans('issue.affects_safety') . ' · ' : '';
    }

    /**
     * How long ago, in days under two weeks, weeks under two months, months after.
     *
     * @return array{unit: string, n: int}
     */
    private static function ago(int $days): array
    {
        return match (true) {
            $days < 14 => ['unit' => 'day', 'n' => $days],
            $days < 61 => ['unit' => 'week', 'n' => intdiv($days, 7)],
            default => ['unit' => 'month', 'n' => intdiv($days, 30)],
        };
    }

    /**
     * "PCP · Toyota Financial Services: on track for 31,200 mi against
     * 30,000 mi. Projected from your average daily distance."
     */
    private function mileageDetail(?FinanceFinding $finding): string
    {
        $mileage = $finding?->mileage;
        if ($mileage === null) {
            return '';
        }

        return $this->translator->trans('attention.finance.mileage_detail', [
            'agreement' => $this->agreementName($finding),
            'projected' => $this->formatter->aboutDistance($mileage->projectedKm, $mileage->unit),
            'allowance' => $this->formatter->distance($mileage->allowanceKm, 0, $mileage->unit),
        ]);
    }

    /** "PCP · Toyota Financial Services". */
    private function agreementName(?FinanceFinding $finding): string
    {
        if ($finding === null) {
            return '';
        }

        return $this->translator->trans($finding->agreement->type()->labelKey()) . ' · ' . $finding->agreement->data->lender;
    }

    /**
     * A drift's likely causes, each only when its fact holds, then the
     * line that always applies (spec.md §7.24 item 7). Empty for other kinds.
     *
     * @return list<string>
     */
    public function causes(AttentionItem $item): array
    {
        $drift = $item->drift;
        if ($drift === null) {
            return [];
        }
        $causes = [];
        if ($drift->gradeFrom !== null && $drift->gradeTo !== null) {
            $causes[] = $this->translator->trans('attention.drift.cause.grade', [
                'from' => $this->translator->trans('fuel.grade_short.' . $drift->gradeFrom->value),
                'to' => $this->translator->trans('fuel.grade_short.' . $drift->gradeTo->value),
            ]);
        }
        if ($drift->tyresFittedOn !== null) {
            $causes[] = $this->translator->trans('attention.drift.cause.tyres', [
                'date' => $this->formatter->date($drift->tyresFittedOn),
            ]);
        }
        $facts = ['winter' => $drift->winter, 'service' => $drift->serviceOverdue, 'short' => $drift->shortTanks];
        foreach ($facts as $key => $holds) {
            if ($holds) {
                $causes[] = $this->translator->trans('attention.drift.cause.' . $key);
            }
        }
        $causes[] = $this->translator->trans('attention.drift.cause.always');

        return $causes;
    }

    /**
     * One line for a notification: "Golf GTI: 3 fill-ups look unusual".
     */
    public function line(AttentionItem $item): string
    {
        return $this->translator->trans('attention.line', [
            'vehicle' => $item->vehicle->name(),
            'title' => $this->title($item),
        ]);
    }

    /**
     * "Economy is about 15% worse over the last 5 tanks than your 12-month
     * average (38.2 against 45.1 mpg)": the percentage is worked out from
     * the two figures as shown, so it reads right in the viewer's unit.
     */
    private function driftTitle(?DriftFinding $drift): string
    {
        if ($drift === null) {
            return '';
        }
        $electric = $drift->isElectric();
        $recent = $this->formatter->economyValue($drift->recentDistanceKm, $drift->recentVolume, $drift->kind);
        $baseline = $this->formatter->economyValue($drift->baselineDistanceKm, $drift->baselineVolume, $drift->kind);
        $percent = $recent === null || $baseline === null || $baseline == 0.0
            ? 0
            : (int) round(abs($recent - $baseline) / $baseline * 100);

        return $this->translator->trans('attention.drift.title', [
            'percent' => $percent,
            'electric' => $electric ? 'yes' : 'no',
            'tanks' => $drift->tanks,
            'recent' => $this->formatter->economy($drift->recentDistanceKm, $drift->recentVolume, $drift->kind),
            'baseline' => $this->formatter->economy($drift->baselineDistanceKm, $drift->baselineVolume, $drift->kind),
        ]);
    }

    /**
     * "Fill-up on 12 Sep 2026: £13.90/L, about 10× your usual £1.39/L".
     */
    private function priceTitle(?PriceFinding $price, string $currency): string
    {
        if ($price === null) {
            return '';
        }
        $kind = $price->entry->data->fuel->kind();
        $electric = $kind === EnergyKind::Electric;

        return $this->translator->trans('attention.price.title', [
            'electric' => $electric ? 'yes' : 'no',
            'date' => $this->formatter->instantDate($price->entry->data->filledAt),
            'price' => $this->formatter->unitPrice($price->entry->data->pricePerUnit, $currency, $kind),
            'ratio' => $this->ratio($price->ratio, $this->formatter->unitPrice(
                Decimal::round($price->median, 6),
                $currency,
                $kind,
            )),
        ]);
    }

    /**
     * "Service on 14 Mar 2026 cost £6,400, about 30× your usual £212".
     */
    private function costTitle(?CostFinding $cost, string $currency): string
    {
        if ($cost === null) {
            return '';
        }

        return $this->translator->trans('attention.cost.title', [
            'category' => $this->translator->trans('maintenance.category.' . $cost->entry->data->category->value),
            'date' => $this->formatter->date($cost->entry->data->performedOn),
            'amount' => $this->formatter->money($cost->entry->data->cost, $currency),
            'ratio' => $this->ratio($cost->ratio, $this->formatter->money(Decimal::round($cost->median, 3), $currency)),
        ]);
    }

    /**
     * "about 10× your usual …" from 2× up, else "about 40% above (below)
     * your usual …".
     */
    private function ratio(string $ratio, string $usual): string
    {
        $value = (float) $ratio;
        if ($value >= 2) {
            return $this->translator->trans('attention.ratio', [
                'direction' => 'times',
                'ratio' => $this->formatter->number($value, $value >= 10 ? 0 : 1),
                'usual' => $usual,
            ]);
        }

        return $this->translator->trans('attention.ratio', [
            'direction' => $value > 1 ? 'above' : 'below',
            'percent' => (int) round(abs($value - 1) * 100),
            'usual' => $usual,
        ]);
    }

    /**
     * "Expired 3 days ago", "Overdue by 12 days", "Overdue at 48,000 mi".
     */
    private function overdue(ForecastItem $item, ?int $days): string
    {
        if ($days !== null && $days > 0) {
            $expires = $item->source === ForecastSource::Document || $item->source === ForecastSource::FirstInspection;

            return $this->translator->trans($expires ? 'reminders.when.expired' : 'reminders.when.overdue', ['days' => $days]);
        }
        if ($item->dueKm !== null) {
            return $this->translator->trans('attention.overdue.at', ['odometer' => $this->formatter->distance($item->dueKm)]);
        }

        return $this->translator->trans('reminders.when.overdue_now');
    }
}
