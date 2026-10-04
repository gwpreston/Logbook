<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Fuel\FuelChoice;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Service\Import\DateOrder;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;

/**
 * The mapping page's choices for one vehicle of an export (spec.md §7.13
 * *Map*), read from and written back to its GET form.
 */
final readonly class AppImportOptions
{
    public const string NEW_VEHICLE = 'new';
    public const string SKIP = 'skip';

    /**
     * @param string $vehicle a vehicle id, NEW_VEHICLE or SKIP
     * @param array<int, string> $categories cost category id → "maintenance:<code>", "expense:<code>" or SKIP
     * @param array<int, string> $fuels fuel code → a fuel choice ("petrol", "petrol:e10_95") or SKIP
     */
    public function __construct(
        public string $vehicle,
        public DistanceUnit $distanceUnit,
        public VolumeUnit $volumeUnit,
        public DateOrder $dateOrder,
        public array $categories,
        public array $fuels,
        public bool $schedules = false,
    ) {
    }

    /**
     * The form's values over the guess; anything unreadable keeps the guess.
     *
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query, self $guess): self
    {
        $vehicle = is_string($query['vehicle'] ?? null) && preg_match('/^(\d+|new|skip)$/', $query['vehicle']) === 1
            ? $query['vehicle']
            : $guess->vehicle;

        $categories = $guess->categories;
        foreach (is_array($query['cat'] ?? null) ? $query['cat'] : [] as $id => $target) {
            if (array_key_exists((int) $id, $categories) && is_string($target) && self::isCategoryTarget($target)) {
                $categories[(int) $id] = $target;
            }
        }
        $fuels = $guess->fuels;
        foreach (is_array($query['fuel'] ?? null) ? $query['fuel'] : [] as $code => $target) {
            if (array_key_exists((int) $code, $fuels) && is_string($target) && self::isFuelTarget($target)) {
                $fuels[(int) $code] = $target;
            }
        }

        return new self(
            vehicle: $vehicle,
            distanceUnit: DistanceUnit::tryFrom(self::text($query, 'distance_unit')) ?? $guess->distanceUnit,
            volumeUnit: VolumeUnit::tryFrom(self::text($query, 'volume_unit')) ?? $guess->volumeUnit,
            dateOrder: DateOrder::tryFrom(self::text($query, 'date_order')) ?? $guess->dateOrder,
            categories: $categories,
            fuels: $fuels,
            schedules: array_key_exists('vehicle', $query) ? ($query['schedules'] ?? '') === '1' : $guess->schedules,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return [
            'vehicle' => $this->vehicle,
            'distance_unit' => $this->distanceUnit->value,
            'volume_unit' => $this->volumeUnit->value,
            'date_order' => $this->dateOrder->value,
            'cat' => $this->categories,
            'fuel' => $this->fuels,
            'schedules' => $this->schedules ? '1' : '',
        ];
    }

    public function vehicleId(): ?int
    {
        return ctype_digit($this->vehicle) ? (int) $this->vehicle : null;
    }

    public function createsVehicle(): bool
    {
        return $this->vehicle === self::NEW_VEHICLE;
    }

    public function skipsVehicle(): bool
    {
        return $this->vehicle === self::SKIP;
    }

    /**
     * Where a cost category's rows go: [module, category], or null for
     * *Don't import*.
     *
     * @return array{0: 'maintenance', 1: MaintenanceCategory}|array{0: 'expense', 1: ExpenseCategory}|null
     */
    public function categoryTarget(int $id): ?array
    {
        [$module, $code] = array_pad(explode(':', $this->categories[$id] ?? self::SKIP, 2), 2, '');

        return match ($module) {
            'maintenance' => ($c = MaintenanceCategory::tryFrom($code)) === null ? null : ['maintenance', $c],
            'expense' => ($c = ExpenseCategory::tryFrom($code)) === null ? null : ['expense', $c],
            default => null,
        };
    }

    /**
     * What a fuel code's fill-ups are, or null for *Don't import*.
     */
    public function fuelTarget(int $code): ?FuelChoice
    {
        $target = $this->fuels[$code] ?? self::SKIP;

        return $target === self::SKIP ? null : FuelChoice::fromValue($target);
    }

    private static function isCategoryTarget(string $target): bool
    {
        if ($target === self::SKIP) {
            return true;
        }
        [$module, $code] = array_pad(explode(':', $target, 2), 2, '');

        return ($module === 'maintenance' && MaintenanceCategory::tryFrom($code) !== null)
            || ($module === 'expense' && ExpenseCategory::tryFrom($code) !== null);
    }

    private static function isFuelTarget(string $target): bool
    {
        return $target === self::SKIP || FuelChoice::fromValue($target) !== null;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function text(array $query, string $key): string
    {
        return is_string($query[$key] ?? null) ? $query[$key] : '';
    }
}
