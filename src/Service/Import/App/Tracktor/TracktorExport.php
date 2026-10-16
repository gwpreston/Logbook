<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

use DateTimeImmutable;

/**
 * A Tracktor export, read (spec.md §7.13 *The Tracktor reader*): the
 * whole garage, each vehicle with its rows.
 */
final readonly class TracktorExport
{
    /**
     * @param list<TracktorVehicle> $vehicles
     * @param list<string> $unread the keys of `data` Logbook doesn't read
     */
    public function __construct(
        public string $fileName,
        /** When it was exported; null when the file doesn't say. */
        public ?DateTimeImmutable $exportedAt,
        public TracktorConfig $config,
        public array $vehicles,
        public array $unread,
        /** Tracktor 1.x: `insurances` and `puccs`, no reminders. */
        public bool $legacy = false,
        /** Rows naming a vehicle the export doesn't hold. */
        public int $orphans = 0,
    ) {
    }

    public function vehicle(string $id): ?TracktorVehicle
    {
        foreach ($this->vehicles as $vehicle) {
            if ($vehicle->id === $id) {
                return $vehicle;
            }
        }

        return null;
    }

    public function rowCount(): int
    {
        $count = 0;
        foreach ($this->vehicles as $v) {
            $count += 1 + count($v->fills) + count($v->services) + count($v->documents) + count($v->reminders);
        }

        return $count;
    }

    /**
     * The document types the export uses, in TracktorDocument::TYPES order.
     *
     * @return list<string>
     */
    public function documentTypes(): array
    {
        $used = [];
        foreach ($this->vehicles as $vehicle) {
            foreach ($vehicle->documents as $document) {
                $used[$document->type] = true;
            }
        }

        return array_values(array_filter(TracktorDocument::TYPES, static fn (string $t): bool => isset($used[$t])));
    }

    public function hasFuel(string $fuelType): bool
    {
        foreach ($this->vehicles as $vehicle) {
            if ($vehicle->fuelType === $fuelType && $vehicle->fills !== []) {
                return true;
            }
        }

        return false;
    }

    public function hasReminders(): bool
    {
        foreach ($this->vehicles as $vehicle) {
            if ($vehicle->reminders !== []) {
                return true;
            }
        }

        return false;
    }

    public function hasServices(): bool
    {
        foreach ($this->vehicles as $vehicle) {
            if ($vehicle->services !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every file name the export points at.
     *
     * @return list<string>
     */
    public function fileNames(): array
    {
        $names = [];
        foreach ($this->vehicles as $vehicle) {
            foreach ($vehicle->fileNames() as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }
}
