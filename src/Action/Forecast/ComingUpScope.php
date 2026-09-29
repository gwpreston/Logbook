<?php

declare(strict_types=1);

namespace Logbook\Action\Forecast;

use Logbook\Domain\Vehicle\Vehicle;

/**
 * Which vehicles `/upcoming` and its CSV cover: every active vehicle, or
 * the one `?vehicle=` names, as the dashboard's chips do (an unknown or
 * archived id means every vehicle).
 */
final readonly class ComingUpScope
{
    /**
     * @param list<Vehicle> $active
     * @param list<Vehicle> $vehicles
     */
    private function __construct(
        public array $active,
        public ?Vehicle $selected,
        public array $vehicles,
    ) {
    }

    /**
     * @param list<Vehicle> $active the owner's active vehicles
     * @param array<array-key, mixed> $query
     */
    public static function of(array $active, array $query): self
    {
        $id = $query['vehicle'] ?? null;
        foreach ($active as $vehicle) {
            if (is_string($id) && (string) $vehicle->id === $id) {
                return new self($active, $vehicle, [$vehicle]);
            }
        }

        return new self($active, null, $active);
    }

    /**
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->selected === null ? [] : ['vehicle' => (string) $this->selected->id];
    }
}
