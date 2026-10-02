<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

use DateTimeImmutable;

final readonly class Vehicle
{
    public function __construct(
        public int $id,
        public int $userId,
        public VehicleData $data,
        public VehicleStatus $status,
        /** Path relative to UPLOAD_PATH, or null. */
        public ?string $photoPath,
        public ?string $photoMime,
        public ?DateTimeImmutable $archivedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Why it left the garage (Phase 27.2); null = none recorded. */
        public ?Disposal $disposal = null,
        /** The total-loss incident when written off. */
        public ?int $disposalIncidentId = null,
    ) {
    }

    /**
     * Nickname if set, else "Make Model".
     */
    public function name(): string
    {
        return $this->data->nickname ?? trim($this->data->make . ' ' . $this->data->model);
    }

    /**
     * The descriptive line: "2019 Ford Focus 1.5 EcoBoost ST-Line X", skipping
     * the parts that are not set; includes the make and model even when a
     * nickname is shown (spec.md §7.1).
     */
    public function description(): string
    {
        $parts = [
            $this->data->year === null ? '' : (string) $this->data->year,
            $this->data->make,
            $this->data->model,
            $this->data->variant ?? '',
        ];

        return implode(' ', array_filter(array_map(trim(...), $parts), static fn (string $part): bool => $part !== ''));
    }

    public function isArchived(): bool
    {
        return $this->status === VehicleStatus::Archived;
    }

    /**
     * Archived as a total loss (spec.md §7.29 *Total loss*): labelled
     * "Written off" where a sold one says "Sold", whatever modules are on.
     */
    public function isWrittenOff(): bool
    {
        return $this->disposal === Disposal::WrittenOff;
    }

    public function hasPhoto(): bool
    {
        return $this->photoPath !== null;
    }

    /**
     * Changes whenever the photo is replaced, for cache-busting photo URLs.
     */
    public function photoVersion(): string
    {
        return $this->photoPath === null ? '' : substr(hash('sha256', $this->photoPath), 0, 12);
    }
}
