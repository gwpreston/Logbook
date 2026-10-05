<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Apportion;
use Logbook\Support\Number\Decimal;

/**
 * *What changed* (spec.md §7.35): a year's change in cost per distance
 * against the year before, split into contributions that add up exactly to
 * it. Pure arithmetic: the causes and their sizes come from Logbook, and
 * Ask Logbook only words them.
 *
 * Every year's part rates already add up exactly to its total, so the
 * parts' changes add up to the total's. Each split below a part is worked
 * out in exact fractions and shared out (largest remainder) so it adds up
 * exactly to that part's change.
 */
final readonly class CostChange
{
    /**
     * @param list<ChangeLine> $lines largest first, the small ones grouped last
     */
    private function __construct(
        public TrueCost $before,
        public TrueCost $after,
        /** after − before per km: exactly the sum of the lines. */
        public string $perKm,
        public array $lines,
    ) {
    }

    /**
     * Null unless both years have at least 500 km and a cost per distance.
     *
     * @param string $smallPerKm a line smaller than this (per km) joins *Other small changes*
     */
    public static function between(TrueCost $before, TrueCost $after, string $smallPerKm): ?self
    {
        if (!$before->isComparable() || !$after->isComparable()) {
            return null;
        }
        assert($before->perKm !== null && $after->perKm !== null);

        $lines = [];
        foreach (TruePart::cases() as $part) {
            $was = $before->rate($part) ?? '0';
            $now = $after->rate($part) ?? '0';
            $change = Decimal::subtract($now, $was);
            if ($part === TruePart::Fuel) {
                $line = self::fuel($before, $after, $change);
                if ($line !== null) {
                    $lines[] = $line;
                }
            } elseif ($part->isFixed() && $before->rate($part) !== null && $after->rate($part) !== null) {
                array_push($lines, ...self::fixed($part, $before, $after, $change));
            } elseif (Decimal::compare($change, '0') !== 0) {
                $lines[] = new ChangeLine(
                    $part === TruePart::Maintenance || $part === TruePart::Other ? ChangeCause::Part : ChangeCause::Amount,
                    $change,
                    $part,
                    amountChange: self::amountChange($part, $before, $after),
                );
            }
        }
        $payouts = Decimal::subtract($after->payoutsPerKm ?? '0', $before->payoutsPerKm ?? '0');
        if (Decimal::compare($payouts, '0') !== 0) {
            $lines[] = new ChangeLine(ChangeCause::Payouts, $payouts, amountChange: $after->payouts->subtract($before->payouts));
        }

        return new self($before, $after, Decimal::subtract($after->perKm, $before->perKm), self::ordered($lines, $smallPerKm));
    }

    /**
     * The fuel part's change, split per energy into price and economy.
     */
    private static function fuel(TrueCost $before, TrueCost $after, string $change): ?ChangeLine
    {
        $kinds = [];
        foreach ([...$before->energies, ...$after->energies] as $use) {
            $kinds[$use->kind->value] = $use->kind;
        }
        if ($kinds === [] && Decimal::compare($change, '0') === 0) {
            return null;
        }
        $kinds = array_values($kinds);
        $was = self::energyRates($before, $kinds);
        $now = self::energyRates($after, $kinds);

        $details = [];
        foreach ($kinds as $i => $kind) {
            $delta = Decimal::subtract($now[$i], $was[$i]);
            $old = self::use($before, $kind);
            $new = self::use($after, $kind);
            if ($old === null || $new === null || self::isZero($old->units) || self::isZero($new->units)) {
                if (Decimal::compare($delta, '0') !== 0) {
                    $details[] = new ChangeLine(ChangeCause::Energy, $delta, TruePart::Fuel, $kind);
                }
                continue;
            }
            assert($before->distanceKm !== null && $after->distanceKm !== null);
            $p0 = self::money($old->cost)->dividedBy(BigRational::of($old->units));
            $p1 = self::money($new->cost)->dividedBy(BigRational::of($new->units));
            $c0 = BigRational::of($old->units)->dividedBy(BigRational::of($before->distanceKm));
            $c1 = BigRational::of($new->units)->dividedBy(BigRational::of($after->distanceKm));
            [$price, $economy] = Apportion::toTarget(
                [$p1->minus($p0)->multipliedBy($c0), $c1->minus($c0)->multipliedBy($p1)],
                $delta,
                TrueCost::SCALE,
            );
            if (!self::isZero($price)) {
                $details[] = new ChangeLine(ChangeCause::Price, $price, TruePart::Fuel, $kind, self::fraction($p1, $p0));
            }
            if (!self::isZero($economy)) {
                $details[] = new ChangeLine(ChangeCause::Economy, $economy, TruePart::Fuel, $kind, self::fraction($c1, $c0));
            }
        }
        if ($details === [] && Decimal::compare($change, '0') === 0) {
            return null;
        }

        return new ChangeLine(
            ChangeCause::Part,
            $change,
            TruePart::Fuel,
            amountChange: self::amountChange(TruePart::Fuel, $before, $after),
            details: $details,
        );
    }

    /**
     * Documents or depreciation: the change from driving a different
     * distance with this year's amount, and the change in the amount
     * itself at last year's distance.
     *
     * @return list<ChangeLine>
     */
    private static function fixed(TruePart $part, TrueCost $before, TrueCost $after, string $change): array
    {
        $d0 = $part === TruePart::Depreciation ? $before->depreciationKm : $before->distanceKm;
        $d1 = $part === TruePart::Depreciation ? $after->depreciationKm : $after->distanceKm;
        $a0 = $before->amount($part);
        $a1 = $after->amount($part);
        assert($d0 !== null && $d1 !== null && $a0 !== null && $a1 !== null);
        $amount1 = self::money($a1);
        $distance = $amount1->dividedBy(BigRational::of($d1))->minus($amount1->dividedBy(BigRational::of($d0)));
        $amount = $amount1->minus(self::money($a0))->dividedBy(BigRational::of($d0));
        [$byDistance, $byAmount] = Apportion::toTarget([$distance, $amount], $change, TrueCost::SCALE);

        $lines = [];
        if (Decimal::compare($byDistance, '0') !== 0) {
            $lines[] = new ChangeLine(ChangeCause::Distance, $byDistance, $part, distanceKm: Decimal::subtract($d1, $d0));
        }
        if (Decimal::compare($byAmount, '0') !== 0) {
            $lines[] = new ChangeLine(ChangeCause::Amount, $byAmount, $part, amountChange: $a1->subtract($a0));
        }

        return $lines;
    }

    /**
     * Each energy's rate in a year, shared out so they add up exactly to
     * that year's fuel rate.
     *
     * @param list<EnergyKind> $kinds
     * @return list<string>
     */
    private static function energyRates(TrueCost $cost, array $kinds): array
    {
        assert($cost->distanceKm !== null);
        $parts = [];
        foreach ($kinds as $kind) {
            $use = self::use($cost, $kind);
            $parts[] = $use === null
                ? BigRational::zero()
                : self::money($use->cost)->dividedBy(BigRational::of($cost->distanceKm));
        }

        return Apportion::toTarget($parts, $cost->rate(TruePart::Fuel) ?? '0', TrueCost::SCALE);
    }

    /**
     * Largest first; lines under the threshold grouped as one, last.
     *
     * @param list<ChangeLine> $lines
     * @return list<ChangeLine>
     */
    private static function ordered(array $lines, string $smallPerKm): array
    {
        usort($lines, static fn (ChangeLine $a, ChangeLine $b): int
            => Decimal::compare(self::abs($b->perKm), self::abs($a->perKm)));
        $kept = [];
        $small = [];
        foreach ($lines as $line) {
            if (Decimal::compare(self::abs($line->perKm), $smallPerKm) < 0) {
                $small[] = $line;
            } else {
                $kept[] = $line;
            }
        }
        if ($small !== []) {
            $sum = '0';
            foreach ($small as $line) {
                $sum = Decimal::add($sum, $line->perKm);
            }
            $kept[] = new ChangeLine(ChangeCause::Small, $sum, details: $small);
        }

        return $kept;
    }

    private static function amountChange(TruePart $part, TrueCost $before, TrueCost $after): ?Money
    {
        $was = $before->amount($part);
        $now = $after->amount($part);
        if ($was === null && $now === null) {
            return null;
        }

        return ($now ?? Money::zero($after->currency))->subtract($was ?? Money::zero($before->currency));
    }

    private static function use(TrueCost $cost, EnergyKind $kind): ?EnergyUse
    {
        foreach ($cost->energies as $use) {
            if ($use->kind === $kind) {
                return $use;
            }
        }

        return null;
    }

    private static function fraction(BigRational $now, BigRational $was): ?string
    {
        if ($was->isZero()) {
            return null;
        }

        return $now->dividedBy($was)->minus(1)->toScale(6, RoundingMode::HalfUp)->toString();
    }

    private static function money(Money $money): BigRational
    {
        return BigRational::of($money->toDecimal(Money::SCALE));
    }

    private static function isZero(string $decimal): bool
    {
        return Decimal::compare($decimal, '0') === 0;
    }

    private static function abs(string $decimal): string
    {
        return ltrim($decimal, '-');
    }
}
