<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

/**
 * One of Tracktor's `vehicles` with everything logged against it.
 */
final readonly class TracktorVehicle
{
    /** Tracktor's types that become a Logbook bike; every other is a car. */
    public const array BIKE_TYPES = ['motorcycle', 'scooter'];
    /** Types Logbook has nothing like: imported as a car, highlighted (#467). */
    public const array ODD_TYPES = ['bus', 'farm_vehicle', 'yacht'];

    /**
     * @param array<string, string> $customFields
     * @param list<TracktorFill> $fills
     * @param list<TracktorServiceLog> $services
     * @param list<TracktorDocument> $documents
     * @param list<TracktorReminder> $reminders
     */
    public function __construct(
        public int $line,
        public string $id,
        public string $make,
        public string $model,
        public ?int $year,
        public string $plate,
        public string $vin,
        public string $color,
        public ?string $odometer,
        public ?string $image,
        /** `petrol`, `diesel`, `electric`, `lpg` or `cng`. */
        public string $fuelType,
        /** Null in a Tracktor 1.x export, which has no type. */
        public ?string $vehicleType,
        public array $customFields,
        public array $fills = [],
        public array $services = [],
        public array $documents = [],
        public array $reminders = [],
    ) {
    }

    public function name(): string
    {
        $parts = array_filter([$this->make, $this->model, $this->plate], static fn (string $p): bool => $p !== '');

        return trim(implode(' ', $parts));
    }

    public function isBike(): bool
    {
        return in_array($this->vehicleType, self::BIKE_TYPES, true);
    }

    public function hasOddType(): bool
    {
        return in_array($this->vehicleType, self::ODD_TYPES, true);
    }

    /**
     * The ids of this vehicle and every row logged against it.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_filter(array_merge(
            [$this->id],
            array_map(static fn (TracktorFill $r): string => $r->id, $this->fills),
            array_map(static fn (TracktorServiceLog $r): string => $r->id, $this->services),
            array_map(static fn (TracktorDocument $r): string => $r->id, $this->documents),
            array_map(static fn (TracktorReminder $r): string => $r->id, $this->reminders),
        ), static fn (string $id): bool => $id !== ''));
    }

    /**
     * File names the rows and the photo point at, in the order they appear.
     *
     * @return list<string>
     */
    public function fileNames(): array
    {
        $names = [];
        $attachments = array_map(
            static fn ($r): ?string => $r->attachment,
            [...$this->fills, ...$this->services, ...$this->documents],
        );
        foreach ([$this->image, ...$attachments] as $name) {
            if ($name !== null && $name !== '') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }
}
