<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

use DateTimeImmutable;

/**
 * A physical part on (or once on, or kept for) a vehicle (spec.md §6 Part,
 * §7.39). Its status is replayed from its fittings; distance is derived.
 */
final readonly class Part
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public PartData $data,
        public PartStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who added it; null = a former user. */
        public ?int $createdBy = null,
    ) {
    }

    /**
     * "Bilstein B14 coilovers" with its brand when the name doesn't start with it.
     */
    public function label(): string
    {
        $brand = $this->data->brand;
        if ($brand === null || stripos($this->data->name, $brand) === 0) {
            return $this->data->name;
        }

        return $brand . ' ' . $this->data->name;
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function filesOwner(): array
    {
        return ['part', $this->id];
    }
}
