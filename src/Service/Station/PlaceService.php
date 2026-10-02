<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Station\Place;
use Logbook\Domain\Station\PlaceData;
use Logbook\Domain\Station\Station;
use Logbook\Domain\User\User;
use Logbook\Repository\PlaceRepository;
use Logbook\Support\Geo\Haversine;
use Psr\Clock\ClockInterface;

/**
 * A user's saved places and the straight-line distances from them
 * (spec.md §7.33 *Places*, *Distances*). Every read is the user's own:
 * places are never shown to anyone else.
 */
final readonly class PlaceService
{
    public function __construct(private PlaceRepository $places, private ClockInterface $clock)
    {
    }

    /**
     * @return list<Place>
     */
    public function list(User $user): array
    {
        return $this->places->listForUser($user->id);
    }

    public function find(User $user, int $id): ?Place
    {
        return $this->places->find($user->id, $id);
    }

    public function create(User $user, PlaceData $data): int
    {
        return $this->places->insert($user->id, $data, $this->clock->now());
    }

    public function update(User $user, Place $place, PlaceData $data): void
    {
        $this->places->update($user->id, $place->id, $data, $this->clock->now());
    }

    public function delete(User $user, Place $place): void
    {
        $this->places->delete($user->id, $place->id);
    }

    /**
     * The straight-line distance in km from each place to the station, in
     * the places' order; none when the station has no position.
     *
     * @param list<Place> $places
     * @return list<array{place: Place, km: float}>
     */
    public static function distances(array $places, Station $station): array
    {
        $data = $station->data;
        if (!$data->hasPosition()) {
            return [];
        }

        return array_map(
            static fn (Place $place): array => [
                'place' => $place,
                'km' => Haversine::km(
                    (float) $place->data->latitude,
                    (float) $place->data->longitude,
                    (float) $data->latitude,
                    (float) $data->longitude,
                ),
            ],
            $places,
        );
    }
}
