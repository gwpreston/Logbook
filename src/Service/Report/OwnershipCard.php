<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Money\Money;

/**
 * One vehicle's card on the Cost of ownership page (spec.md §7.7 *Cost of
 * ownership page*): its cost of ownership and the five *Since bought* parts
 * of §7.35 (#189) as a stacked bar by amount. Pure: no database.
 */
final readonly class OwnershipCard
{
    public function __construct(
        public VehicleTrueCost $trueCost,
        /** The active agreement's type, when the viewer may see the vehicle's finance. */
        public ?AgreementType $agreementType = null,
    ) {
    }

    public function vehicle(): Vehicle
    {
        return $this->trueCost->vehicle;
    }

    public function cost(): OwnershipCost
    {
        return $this->trueCost->ownership;
    }

    /**
     * Running costs plus depreciation; the running costs alone without a
     * price and a value (see isRunningOnly).
     */
    public function total(): Money
    {
        return $this->cost()->total ?? $this->cost()->running;
    }

    /**
     * Still owned: neither archived nor sold (spec.md §7.7, #187).
     */
    public function isActive(): bool
    {
        return !$this->vehicle()->isArchived() && $this->vehicle()->data->saleDate === null;
    }

    public function isRunningOnly(): bool
    {
        return $this->cost()->total === null;
    }

    /**
     * The positive parts, largest first, each with its share of the bar in
     * percent. A gain and the payouts are left out (see negatives()).
     *
     * @return list<array{part: TruePart, amount: Money, percent: float}>
     */
    public function segments(): array
    {
        $since = $this->trueCost->sinceBought;
        $parts = [];
        $positive = 0;
        foreach (TruePart::cases() as $part) {
            $amount = $since->amount($part);
            if ($amount !== null && $amount->micros > 0) {
                $parts[] = ['part' => $part, 'amount' => $amount];
                $positive += $amount->micros;
            }
        }
        usort($parts, static fn (array $a, array $b): int => $b['amount']->micros <=> $a['amount']->micros);

        return array_map(
            static fn (array $p): array => $p + ['percent' => round($p['amount']->micros / $positive * 100, 2)],
            $parts,
        );
    }

    /**
     * What is taken off rather than drawn: the insurance payouts and a
     * depreciation gain, each as a negative amount.
     *
     * @return list<array{labelKey: string, amount: Money}>
     */
    public function negatives(): array
    {
        $since = $this->trueCost->sinceBought;
        $lines = [];
        if (!$since->payouts->isZero()) {
            $lines[] = ['labelKey' => 'true_cost.payouts', 'amount' => Money::zero($since->currency)->subtract($since->payouts)];
        }
        if ($since->depreciation !== null && $since->depreciation->isNegative()) {
            $lines[] = ['labelKey' => 'ownership.page.gain', 'amount' => $since->depreciation];
        }

        return $lines;
    }
}
