<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreSet;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Tyre\TyreService;
use Logbook\Support\Api\Serializer;

/**
 * Tyre changes and sets over the API (spec.md §7.17, §7.20 *Phase 39*),
 * from TyreService as the Tyres tab reads them.
 */
final readonly class ApiTyres
{
    public function __construct(private TyreService $tyres)
    {
    }

    /**
     * Every change, tread checks included, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function changes(Vehicle $vehicle): array
    {
        $tyres = $this->byId($vehicle);

        return array_map(
            static fn (TyreChange $change): array => Serializer::tyreChange($change, $tyres),
            $this->tyres->changes($vehicle),
        );
    }

    /**
     * The sets of each vehicle, by name, with their tyres.
     *
     * @param list<Vehicle> $vehicles
     * @return list<array<string, mixed>>
     */
    public function sets(array $vehicles): array
    {
        $out = [];
        foreach ($vehicles as $vehicle) {
            $tyres = $this->tyres->tyres($vehicle);
            foreach ($this->tyres->sets($vehicle) as $set) {
                $out[] = Serializer::tyreSet(
                    $set,
                    array_values(array_filter($tyres, static fn (Tyre $tyre): bool => $tyre->setId === $set->id)),
                );
            }
        }

        return $out;
    }

    /**
     * @return array<int, Tyre>
     */
    private function byId(Vehicle $vehicle): array
    {
        $byId = [];
        foreach ($this->tyres->tyres($vehicle) as $tyre) {
            $byId[$tyre->id] = $tyre;
        }

        return $byId;
    }
}
