<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;

/**
 * How to read an uploaded file (spec.md §7.13): which column fills which
 * field, how dates are written, the file's units and time zone.
 */
final readonly class ImportOptions
{
    /**
     * @param array<string, int|null> $mapping field key → column index (null: not in the file)
     */
    public function __construct(
        public array $mapping,
        public DateOrder $dateOrder,
        public DistanceUnit $distanceUnit,
        public VolumeUnit $volumeUnit,
        public string $timezone,
    ) {
    }

    public function column(string $field): ?int
    {
        return $this->mapping[$field] ?? null;
    }

    /**
     * The options as query parameters (the mapping form's fields).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        foreach ($this->mapping as $field => $column) {
            $query['map_' . $field] = $column === null ? '' : (string) $column;
        }

        return $query + [
            'date_order' => $this->dateOrder->value,
            'distance_unit' => $this->distanceUnit->value,
            'volume_unit' => $this->volumeUnit->value,
            'zone' => $this->timezone,
        ];
    }
}
