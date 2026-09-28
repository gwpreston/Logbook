<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Number\Decimal;

/**
 * Two or more back-to-back fill-ups of one vehicle, shown as one row that
 * expands to them (spec.md §7.16): "4 fill-ups · 2 Sep – 17 Sep · £284.10".
 */
final readonly class FillUpRun
{
    private const int SCALE = 3;

    /**
     * @param non-empty-list<ActivityItem> $fills newest first, all of one vehicle
     */
    public function __construct(public array $fills)
    {
    }

    public function isRun(): bool
    {
        return true;
    }

    public function vehicle(): Vehicle
    {
        return $this->fills[0]->vehicle;
    }

    public function newest(): DateTimeImmutable
    {
        return $this->fills[0]->date;
    }

    public function oldest(): DateTimeImmutable
    {
        return $this->fills[count($this->fills) - 1]->date;
    }

    /**
     * Fill-ups of liquid fuel (a plug-in hybrid's charges are counted apart).
     */
    public function fillUps(): int
    {
        return count($this->fills) - $this->charges();
    }

    public function charges(): int
    {
        return count(array_filter($this->fills, static fn (ActivityItem $fill): bool => $fill->isElectric()));
    }

    /**
     * What the fill-ups cost together, in the vehicle's currency.
     */
    public function total(): string
    {
        $total = '0';
        foreach ($this->fills as $fill) {
            $total = Decimal::add($total, $fill->amount ?? '0');
        }

        return Decimal::round($total, self::SCALE);
    }

    public function currency(): ?string
    {
        return $this->fills[0]->currency;
    }

    /**
     * The volume together when every fill-up has the same unit (litres, or
     * kWh for charges); null for a mix.
     */
    public function volume(): ?string
    {
        if ($this->charges() !== 0 && $this->fillUps() !== 0) {
            return null;
        }
        $volume = '0';
        foreach ($this->fills as $fill) {
            $volume = Decimal::add($volume, $fill->volume ?? '0');
        }

        return Decimal::round($volume, self::SCALE);
    }

    public function isElectric(): bool
    {
        return $this->fillUps() === 0;
    }

    public function files(): int
    {
        return array_sum(array_map(static fn (ActivityItem $fill): int => $fill->files, $this->fills));
    }
}
