<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Service\Import\App\Fuelio\FuelioExport;

/**
 * One vehicle of an export, mapped and previewed (spec.md §7.13 *Preview*):
 * a table per section, the vehicle it goes to and the units' sanity line.
 * After importing, it also carries the vehicle written to and the economy
 * check's count of unusual imported fill-ups.
 */
final readonly class AppVehiclePreview
{
    public const string FILLS = 'fills';
    public const string COSTS = 'costs';
    public const string STATIONS = 'stations';
    public const string PHOTOS = 'photos';
    public const string SCHEDULES = 'schedules';

    /** Sections in the order they are shown and imported. */
    public const array SECTIONS = [self::FILLS, self::COSTS, self::STATIONS, self::SCHEDULES, self::PHOTOS];

    /**
     * @param array<string, list<AppRow>> $sections by section key
     */
    public function __construct(
        public FuelioExport $export,
        public AppImportOptions $options,
        /** The existing vehicle it goes to; null for a new one or when skipped. */
        public ?Vehicle $target,
        /** The vehicle to create, when the mapping says so. */
        public ?VehicleData $newVehicle,
        public string $currency,
        public array $sections,
        public ?UnitSanity $sanity,
        public ?Vehicle $written = null,
        public int $unusualFills = 0,
    ) {
    }

    public function isSkipped(): bool
    {
        return $this->options->skipsVehicle();
    }

    /**
     * @return list<AppRow>
     */
    public function rows(string $section): array
    {
        return $this->sections[$section] ?? [];
    }

    public function count(string $section, AppRowStatus $status): int
    {
        return count(array_filter($this->rows($section), static fn (AppRow $r): bool => $r->status === $status));
    }

    public function total(AppRowStatus $status): int
    {
        return array_sum(array_map(fn (string $s): int => $this->count($s, $status), self::SECTIONS));
    }

    /**
     * @return list<AppRow>
     */
    public function withStatus(string $section, AppRowStatus $status): array
    {
        return array_values(array_filter($this->rows($section), static fn (AppRow $r): bool => $r->status === $status));
    }

    public function done(Vehicle $written, int $unusualFills): self
    {
        return new self(
            $this->export,
            $this->options,
            $this->target,
            $this->newVehicle,
            $this->currency,
            $this->sections,
            $this->sanity,
            $written,
            $unusualFills,
        );
    }
}
