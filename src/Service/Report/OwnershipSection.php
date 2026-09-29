<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;

/**
 * The ownership report's rows for the vehicles that use one currency, with
 * the fleet row (spec.md §7.7 *Cost of ownership*). Amounts in different
 * currencies are never added up or converted.
 */
final readonly class OwnershipSection
{
    /** Places kept for money per km. */
    private const int SCALE = 6;

    /**
     * @param list<OwnershipCost> $rows in fleet order
     */
    private function __construct(
        public string $currency,
        public array $rows,
        /** Kilometres owned, summed over the rows that have a distance; null for none. */
        public ?string $distanceKm,
        public Money $running,
        /** Summed over the rows that have one. */
        public Money $depreciation,
        /** Summed over the complete rows; null when none is. */
        public ?Money $total,
        /** How many rows the total covers. */
        public int $completeCount,
        /** Total ÷ distance of the rows with both, or null. */
        public ?string $perKm,
    ) {
    }

    /**
     * @param list<OwnershipCost> $rows all in $currency
     */
    public static function of(string $currency, array $rows): self
    {
        $zero = Money::zero($currency);
        $distance = '0';
        $running = $zero;
        $depreciation = $zero;
        $total = null;
        $complete = 0;
        $bothTotal = $zero;
        $bothKm = '0';

        foreach ($rows as $row) {
            $running = $running->add($row->running);
            if ($row->distanceKm !== null) {
                $distance = Decimal::add($distance, $row->distanceKm);
            }
            if ($row->depreciationCost !== null) {
                $depreciation = $depreciation->add($row->depreciationCost);
            }
            if ($row->total !== null) {
                $total = ($total ?? $zero)->add($row->total);
                $complete++;
                if ($row->distanceKm !== null) {
                    $bothTotal = $bothTotal->add($row->total);
                    $bothKm = Decimal::add($bothKm, $row->distanceKm);
                }
            }
        }

        return new self(
            currency: $currency,
            rows: $rows,
            distanceKm: Decimal::compare($distance, '0') > 0 ? $distance : null,
            running: $running,
            depreciation: $depreciation,
            total: $total,
            completeCount: $complete,
            perKm: Decimal::compare($bothKm, '0') > 0
                ? Decimal::divide($bothTotal->toDecimal(Money::SCALE), $bothKm, self::SCALE)
                : null,
        );
    }

    /**
     * Whether the fleet total leaves some vehicles out ("3 of 4 vehicles").
     */
    public function isTotalPartial(): bool
    {
        return $this->total !== null && $this->completeCount < count($this->rows);
    }
}
