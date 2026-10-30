<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Service\Expense\CostItem;

/**
 * One vehicle's ledger lines, sorted once, so each true-cost period
 * (spec.md §7.35) is handed the lines that can count in it rather than the
 * whole ledger: those dated in the period, and every line with a cover
 * (a document spreads over its cover, and *Cover outside ownership* looks
 * at all of them). Up to 16 years and two 12-month periods are worked out
 * from one ledger; scanning it whole for each was most of the report's
 * working-out (Phase 54.5, K150). Pure.
 */
final readonly class PeriodItems
{
    /** @var list<int> the lines without a cover, by date */
    private array $byDate;

    /** @var list<int> the lines with a cover */
    private array $covered;

    /**
     * @param list<CostItem> $items the vehicle's ledger lines, any date
     */
    public function __construct(private array $items)
    {
        $dated = [];
        $covered = [];
        foreach ($items as $i => $item) {
            if ($item->coverTo !== null) {
                $covered[] = $i;
            } else {
                $dated[] = $i;
            }
        }
        // Stable: lines on the same date keep the ledger's order.
        usort($dated, static fn (int $a, int $b): int => $items[$a]->date <=> $items[$b]->date);
        $this->byDate = $dated;
        $this->covered = $covered;
    }

    /**
     * The lines that can count in $period, in the ledger's order: what
     * TrueCost::forPeriod() would find scanning every line.
     *
     * @return list<CostItem>
     */
    public function for(TrueCostPeriod $period): array
    {
        $keep = $this->covered;
        for ($i = $this->first($period), $n = count($this->byDate); $i < $n; $i++) {
            $index = $this->byDate[$i];
            if ($this->items[$index]->date > $period->to) {
                break;
            }
            $keep[] = $index;
        }
        sort($keep);

        return array_map(fn (int $index): CostItem => $this->items[$index], $keep);
    }

    /**
     * The position of the first line dated on or after the period's start.
     */
    private function first(TrueCostPeriod $period): int
    {
        $low = 0;
        $high = count($this->byDate);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->items[$this->byDate[$middle]]->date < $period->from) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
