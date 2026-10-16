<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Service\Import\App\AppRow;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\UnitSanity;

/**
 * One Tracktor vehicle, mapped and previewed (spec.md §7.13 *The Tracktor
 * reader*): a table per section, the vehicle it goes to, the units' sanity
 * line and any warning. After importing, it also carries the vehicle
 * written to and the economy check's count of unusual imported fill-ups.
 */
final readonly class TracktorVehiclePreview
{
    public const string FILLS = 'fills';
    public const string SERVICES = 'services';
    public const string DOCUMENTS = 'documents';
    public const string REMINDERS = 'reminders';
    public const string SCHEDULES = 'schedules';
    public const string READINGS = 'readings';
    public const string FILES = 'files';
    public const string DETAILS = 'details';

    /** Sections in the order they are shown. */
    public const array SECTIONS = [
        self::FILLS, self::SERVICES, self::DOCUMENTS, self::REMINDERS, self::SCHEDULES,
        self::READINGS, self::FILES, self::DETAILS,
    ];

    /**
     * @param array<string, list<AppRow>> $sections by section key
     * @param list<array{key: string, params: array<string, string>}> $warnings
     */
    public function __construct(
        public TracktorVehicle $source,
        /** The existing vehicle it goes to; null for a new one or when skipped. */
        public ?Vehicle $target,
        /** The vehicle to create, when the mapping says so. */
        public ?VehicleData $newVehicle,
        public bool $skipped,
        public string $currency,
        public array $sections,
        public ?UnitSanity $sanity,
        public array $warnings = [],
        public ?Vehicle $written = null,
        public int $unusualFills = 0,
    ) {
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
            $this->source,
            $this->target,
            $this->newVehicle,
            $this->skipped,
            $this->currency,
            $this->sections,
            $this->sanity,
            $this->warnings,
            $written,
            $unusualFills,
        );
    }
}
