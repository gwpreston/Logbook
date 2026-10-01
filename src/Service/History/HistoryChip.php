<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use Logbook\Domain\Feature\Feature;

/**
 * The kind chips of the History pages (spec.md §7.16): one chosen at a time
 * (`?kind=`), a switched-off module's chip hidden, anything unknown read as
 * *Everything*. Milestones show under *Everything* only.
 */
enum HistoryChip: string
{
    case Everything = 'all';
    case Service = 'service';
    case Fuel = 'fuel';
    case Tyres = 'tyres';
    case Documents = 'documents';
    case Expenses = 'expenses';
    case Mileage = 'mileage';
    /** Incidents (Phase 27.1, spec.md §7.29). */
    case Incidents = 'incidents';
    /** The only place trips are listed (Phase 22, spec.md §7.22). */
    case Trips = 'trips';

    /**
     * The chosen chip; unknown values and switched-off modules fall back to
     * Everything.
     *
     * @param array<string, bool> $enabled module → switched on
     */
    public static function fromQuery(mixed $value, array $enabled): self
    {
        $chip = is_string($value) ? self::tryFrom($value) : null;

        return $chip !== null && $chip->isAvailable($enabled) ? $chip : self::Everything;
    }

    /**
     * @param array<string, bool> $enabled
     * @return list<self>
     */
    public static function available(array $enabled): array
    {
        return array_values(array_filter(self::cases(), static fn (self $chip): bool => $chip->isAvailable($enabled)));
    }

    /**
     * @param array<string, bool> $enabled
     */
    public function isAvailable(array $enabled): bool
    {
        return $this->feature() === null || ($enabled[$this->feature()->value] ?? true);
    }

    /**
     * @return list<ActivityKind>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::Everything => [...ActivityKind::entries(), ActivityKind::Milestone],
            self::Service => [ActivityKind::Maintenance],
            self::Fuel => [ActivityKind::Fuel],
            self::Tyres => [ActivityKind::Tyre],
            self::Documents => [ActivityKind::Document],
            self::Expenses => [ActivityKind::Expense],
            self::Mileage => [ActivityKind::Odometer],
            self::Trips => [ActivityKind::Trip],
            self::Incidents => [ActivityKind::Incident],
        };
    }

    public function feature(): ?Feature
    {
        return match ($this) {
            self::Service => Feature::Maintenance,
            self::Fuel => Feature::Fuel,
            self::Documents => Feature::Compliance,
            self::Tyres => Feature::Tyres,
            self::Trips => Feature::Trips,
            self::Incidents => Feature::Incidents,
            self::Everything, self::Expenses, self::Mileage => null,
        };
    }

    /**
     * Back-to-back fill-ups fold into one row, except when only fill-ups are shown.
     */
    public function folds(): bool
    {
        return $this !== self::Fuel;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Everything => 'history',
            self::Service => 'build',
            self::Fuel => 'local_gas_station',
            self::Tyres => 'tire_repair',
            self::Documents => 'verified_user',
            self::Expenses => 'payments',
            self::Mileage => 'speed',
            self::Trips => 'route',
            self::Incidents => 'car_crash',
        };
    }
}
