<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Support\Money\Money;
use Logbook\Support\Money\ShownAmounts;

/**
 * The ownership report's print table as shown (spec.md §8 *Shown parts
 * add up*, #407): vehicles × (running costs, depreciation) rounded under
 * control, so each vehicle's running costs and depreciation add up to its
 * total and each column to the fleet row. A fleet total over only some
 * vehicles adds up their shown totals. Display only: the exact sums are
 * unchanged.
 */
final readonly class ShownOwnershipSection
{
    /**
     * @param list<Money> $running per row, in the section's order
     * @param list<?Money> $depreciation per row; null where the row has none
     * @param list<?Money> $total per row; null where the row has none
     */
    private function __construct(
        public array $running,
        public array $depreciation,
        public array $total,
        public Money $fleetRunning,
        public ?Money $fleetDepreciation,
        public ?Money $fleetTotal,
    ) {
    }

    /**
     * Row $i as shown, for the template: its running costs, depreciation,
     * total, and its depreciation as a gain in value (negated), which the
     * template shows when the exact depreciation is a gain.
     *
     * @return array{running: Money, depreciation: ?Money, total: ?Money, gain: ?Money}
     */
    public function row(int $i): array
    {
        $depreciation = $this->depreciation[$i];

        return [
            'running' => $this->running[$i],
            'depreciation' => $depreciation,
            'total' => $this->total[$i],
            'gain' => $depreciation === null ? null : Money::zero($depreciation->currency)->subtract($depreciation),
        ];
    }

    public static function of(OwnershipSection $section): self
    {
        $zero = Money::zero($section->currency);
        $cells = [];
        foreach ($section->rows as $i => $row) {
            $cells[$i] = ['running' => $row->running, 'depreciation' => $row->depreciationCost ?? $zero];
        }
        $grid = ShownAmounts::grid($cells, $section->currency);

        $running = [];
        $depreciation = [];
        $total = [];
        $fleetTotal = null;
        foreach ($section->rows as $i => $row) {
            $running[] = $grid['cells'][$i]['running'];
            $depreciation[] = $row->depreciationCost === null ? null : $grid['cells'][$i]['depreciation'];
            $rowTotal = $row->total === null ? null : $grid['rows'][$i];
            $total[] = $rowTotal;
            if ($rowTotal !== null) {
                $fleetTotal = ($fleetTotal ?? $zero)->add($rowTotal);
            }
        }

        return new self(
            $running,
            $depreciation,
            $total,
            $grid['columns']['running'] ?? $zero,
            $section->depreciation === null ? null : ($grid['columns']['depreciation'] ?? $zero),
            $section->total === null ? null : $fleetTotal,
        );
    }
}
