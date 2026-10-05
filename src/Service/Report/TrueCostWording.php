<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Number\Decimal;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How true cost is written (spec.md §7.35), for the pages, the CSV, the API
 * and Ask Logbook alike: period labels, signed rates and the fixed,
 * translated sentences of *What changed*. Rounding here is for display
 * only; the figures behind it add up exactly.
 */
final readonly class TrueCostWording
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
        private DisplayContext $context,
    ) {
    }

    public function part(TruePart $part): string
    {
        return $this->translator->trans($part->labelKey());
    }

    /**
     * "2024", "2026 so far", "2023 from 14 Mar", "2026 to 12 Mar" (the
     * sale), or the name of the 12-month period or *Since bought*.
     */
    public function label(TrueCost $cost): string
    {
        $period = $cost->period;
        if ($period->range !== TrueCostRange::Year) {
            return $this->translator->trans('true_cost.range.' . $period->range->value);
        }
        $year = (string) $period->year;
        $from = $this->format->dayMonth($period->from);
        $to = $this->format->dayMonth($period->to);
        $sale = $cost->vehicle->data->saleDate;
        $sold = $sale !== null && $period->to == $sale;

        return match (true) {
            $period->partialStart && $period->partialEnd && $sold => $this->translator->trans(
                'true_cost.year.between',
                ['year' => $year, 'from' => $from, 'to' => $to],
            ),
            $period->partialStart => $this->translator->trans('true_cost.year.from', ['year' => $year, 'from' => $from]),
            $period->partialEnd && $sold => $this->translator->trans('true_cost.year.to', ['year' => $year, 'to' => $to]),
            $period->partialEnd => $this->translator->trans('true_cost.year.so_far', ['year' => $year]),
            default => $year,
        };
    }

    /**
     * A rate per distance; a negative one (a gain, payouts) with a minus sign.
     */
    public function rate(?string $perKm, string $currency): string
    {
        if ($perKm !== null && Decimal::isCanonical($perKm) && Decimal::compare($perKm, '0') < 0) {
            return '−' . $this->format->perDistance(ltrim($perKm, '-'), $currency);
        }

        return $this->format->perDistance($perKm, $currency);
    }

    /**
     * A change per distance with its sign: "+£0.021/mi", "−£0.005/mi".
     */
    public function signed(string $perKm, string $currency): string
    {
        $compare = Decimal::compare($perKm, '0');
        $size = $this->format->perDistance(ltrim($perKm, '-'), $currency);

        return match ($compare) {
            1 => '+' . $size,
            -1 => '−' . $size,
            default => $size,
        };
    }

    /**
     * 1 for a rise, −1 for a fall, 0 for none.
     */
    public function sign(string $perKm): int
    {
        return Decimal::compare($perKm, '0');
    }

    /**
     * A change against the period before: "↑ £0.03/mi", "↓ £0.01/mi", "No change".
     */
    public function arrow(string $perKm, string $currency): string
    {
        $size = $this->format->perDistance(ltrim($perKm, '-'), $currency);

        return match ($this->sign($perKm)) {
            1 => $this->translator->trans('true_cost.change.up', ['amount' => $size]),
            -1 => $this->translator->trans('true_cost.change.down', ['amount' => $size]),
            default => $this->translator->trans('true_cost.change.same'),
        };
    }

    /**
     * The breakdown in a line: "Fuel £0.14/mi · Maintenance £0.05/mi · … = £0.34/mi".
     */
    public function breakdown(TrueCost $cost): string
    {
        if ($cost->perKm === null) {
            return '';
        }
        $parts = [];
        foreach ($cost->parts() as $part) {
            $parts[] = $this->part($part) . ' ' . $this->rate($cost->rate($part), $cost->currency);
        }
        if ($cost->payoutsPerKm !== null) {
            $parts[] = $this->translator->trans('true_cost.payouts') . ' ' . $this->rate($cost->payoutsPerKm, $cost->currency);
        }

        return implode(' · ', $parts) . ' = ' . $this->rate($cost->perKm, $cost->currency);
    }

    /**
     * One line of *What changed*, as a fixed sentence.
     */
    public function sentence(ChangeLine $line, string $currency): string
    {
        $amount = $this->signed($line->perKm, $currency);
        $part = $line->part === null ? '' : $this->part($line->part);

        return match ($line->cause) {
            ChangeCause::Part => $line->part === TruePart::Fuel && $this->shownDetails($line, $currency) !== []
                ? $this->translator->trans('true_cost.change.fuel', [
                    'part' => $part,
                    'amount' => $amount,
                    'details' => implode('; ', array_map(
                        fn (ChangeLine $d): string => $this->detail($d, $currency),
                        $this->shownDetails($line, $currency),
                    )),
                ])
                : $this->translator->trans('true_cost.change.spent', [
                    'part' => $part,
                    'amount' => $amount,
                    'direction' => $this->direction($line),
                    'money' => $this->moneyChange($line, $currency),
                ]),
            ChangeCause::Amount => $line->part === TruePart::Depreciation
                ? $this->translator->trans('true_cost.change.depreciation', [
                    'amount' => $amount,
                    'direction' => $this->direction($line),
                    'money' => $this->moneyChange($line, $currency),
                ])
                : $this->translator->trans('true_cost.change.spent', [
                    'part' => $part,
                    'amount' => $amount,
                    'direction' => $this->direction($line),
                    'money' => $this->moneyChange($line, $currency),
                ]),
            ChangeCause::Distance => $this->translator->trans('true_cost.change.distance', [
                'part' => $part,
                'amount' => $amount,
                'direction' => Decimal::compare($line->distanceKm ?? '0', '0') < 0 ? 'less' : 'more',
                'distance' => $this->format->distance(ltrim($line->distanceKm ?? '0', '-')),
            ]),
            ChangeCause::Payouts => $this->translator->trans('true_cost.change.payouts', [
                'amount' => $amount,
                'direction' => $line->amountChange !== null && $line->amountChange->isNegative() ? 'less' : 'more',
                'money' => $this->moneyChange($line, $currency),
            ]),
            ChangeCause::Small => $this->rate(ltrim($line->perKm, '-'), $currency) === $this->rate('0', $currency)
                ? $this->translator->trans('true_cost.change.small_none')
                : $this->translator->trans('true_cost.change.small', ['amount' => $amount]),
            ChangeCause::Price, ChangeCause::Economy, ChangeCause::Energy => $this->detail($line, $currency),
        };
    }

    /**
     * A detail of the fuel line: "fuel cost 7% more per litre (+£0.026/mi)".
     */
    private function detail(ChangeLine $line, string $currency): string
    {
        $amount = $this->signed($line->perKm, $currency);
        $energy = ($line->energy ?? EnergyKind::Liquid)->value;
        $percent = $this->format->percent(ltrim($line->fraction ?? '0', '-'));
        $up = $line->fraction !== null && Decimal::compare($line->fraction, '0') > 0;

        return match ($line->cause) {
            ChangeCause::Price => $this->translator->trans('true_cost.change.price', [
                'energy' => $energy,
                'percent' => $percent,
                'direction' => $up ? 'more' : 'less',
                'unit' => $line->energy === EnergyKind::Liquid || $line->energy === null
                    ? $this->context->preferences()->volumeUnit->value
                    : $energy,
                'amount' => $amount,
            ]),
            ChangeCause::Economy => $this->translator->trans('true_cost.change.economy', [
                'energy' => $energy,
                'percent' => $percent,
                // More used per distance is worse economy.
                'direction' => $up ? 'worse' : 'better',
                'amount' => $amount,
            ]),
            default => $this->translator->trans('true_cost.change.energy', [
                'energy' => $energy,
                'direction' => Decimal::compare($line->perKm, '0') > 0 ? 'started' : 'stopped',
                'amount' => $amount,
            ]),
        };
    }

    /**
     * A fuel line's details worth a word: one under 0.2p a mile is left out
     * of the sentence (the figures still add up to the line).
     *
     * @return list<ChangeLine>
     */
    private function shownDetails(ChangeLine $line, string $currency): array
    {
        $small = TrueCostService::smallPerKm($currency, $this->context->preferences()->distanceUnit);

        return array_values(array_filter(
            $line->details,
            static fn (ChangeLine $d): bool => Decimal::compare(ltrim($d->perKm, '-'), $small) >= 0,
        ));
    }

    /**
     * How much more or less was spent, without its sign: "£120.00".
     */
    private function moneyChange(ChangeLine $line, string $currency): string
    {
        return $line->amountChange === null
            ? ''
            : $this->format->money(ltrim($line->amountChange->toDecimal(3), '-'), $currency);
    }

    private function direction(ChangeLine $line): string
    {
        if ($line->amountChange === null || $line->amountChange->isZero()) {
            return 'same';
        }

        return $line->amountChange->isNegative() ? 'less' : 'more';
    }
}
