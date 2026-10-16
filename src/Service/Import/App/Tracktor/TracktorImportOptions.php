<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;

/**
 * The mapping page's choices for a Tracktor export (spec.md §7.13 *The
 * Tracktor reader*), read from and written back to its GET form.
 */
final readonly class TracktorImportOptions
{
    public const string NEW_VEHICLE = 'new';
    public const string SKIP = 'skip';

    /**
     * @param array<string, string> $vehicles Tracktor vehicle id → a Logbook vehicle id, NEW_VEHICLE or SKIP
     * @param string $service a maintenance category for every service record, or SKIP
     * @param array<string, string> $documents Tracktor document type → a Logbook document type, or SKIP
     */
    public function __construct(
        public array $vehicles,
        public DistanceUnit $distanceUnit,
        public VolumeUnit $volumeUnit,
        public string $service,
        public array $documents,
        public bool $reminders = true,
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
        $submitted = array_key_exists('vehicle', $query);
        $vehicles = $guess->vehicles;
        foreach (is_array($query['vehicle'] ?? null) ? $query['vehicle'] : [] as $id => $target) {
            if (!is_string($target) || preg_match('/^(\d+|new|skip)$/', $target) !== 1) {
                continue;
            }
            if (array_key_exists((string) $id, $vehicles)) {
                $vehicles[(string) $id] = $target;
            }
        }
        $documents = $guess->documents;
        foreach (is_array($query['doc'] ?? null) ? $query['doc'] : [] as $type => $target) {
            if (
                array_key_exists((string) $type, $documents)
                && is_string($target)
                && ($target === self::SKIP || ComplianceType::tryFrom($target) !== null)
            ) {
                $documents[(string) $type] = $target;
            }
        }
        $service = self::text($query, 'service');

        return new self(
            vehicles: $vehicles,
            distanceUnit: DistanceUnit::tryFrom(self::text($query, 'distance_unit')) ?? $guess->distanceUnit,
            volumeUnit: VolumeUnit::tryFrom(self::text($query, 'volume_unit')) ?? $guess->volumeUnit,
            service: $service === self::SKIP || MaintenanceCategory::tryFrom($service) !== null ? $service : $guess->service,
            documents: $documents,
            reminders: $submitted ? ($query['reminders'] ?? '') === '1' : $guess->reminders,
            schedules: $submitted ? ($query['schedules'] ?? '') === '1' : $guess->schedules,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return [
            'vehicle' => $this->vehicles,
            'distance_unit' => $this->distanceUnit->value,
            'volume_unit' => $this->volumeUnit->value,
            'service' => $this->service,
            'doc' => $this->documents,
            'reminders' => $this->reminders ? '1' : '',
            'schedules' => $this->schedules ? '1' : '',
        ];
    }

    public function target(string $tracktorId): string
    {
        return $this->vehicles[$tracktorId] ?? self::SKIP;
    }

    public function vehicleId(string $tracktorId): ?int
    {
        $target = $this->target($tracktorId);

        return ctype_digit($target) ? (int) $target : null;
    }

    public function createsVehicle(string $tracktorId): bool
    {
        return $this->target($tracktorId) === self::NEW_VEHICLE;
    }

    public function skipsVehicle(string $tracktorId): bool
    {
        return $this->target($tracktorId) === self::SKIP;
    }

    public function serviceCategory(): ?MaintenanceCategory
    {
        return MaintenanceCategory::tryFrom($this->service);
    }

    public function documentType(string $tracktorType): ?ComplianceType
    {
        return ComplianceType::tryFrom($this->documents[$tracktorType] ?? self::SKIP);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function text(array $query, string $key): string
    {
        return is_string($query[$key] ?? null) ? $query[$key] : '';
    }
}
