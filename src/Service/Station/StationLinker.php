<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\Station\StationName;
use Logbook\Repository\StationRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\I18n\Region;
use Psr\Clock\ClockInterface;

/**
 * Links a fill-up to its station as it is saved (spec.md §7.33), whatever
 * wrote it: the form, CSV import, the API, a scan or an Ask draft. A
 * station id is resolved through merges; a typed name links the station
 * with that normalised name, or creates it. The fill-up's text becomes the
 * station's name. Home charging is never linked (decided 2026-10-02, #131).
 *
 * With the `stations` module off, a fill-up keeps the link it had while its
 * text is unchanged, and nothing is created.
 */
final readonly class StationLinker
{
    public function __construct(
        private StationRepository $stations,
        private FeatureToggles $features,
        private AccessContext $author,
        private ClockInterface $clock,
    ) {
    }

    public function link(FuelEntryData $data, ?FuelEntry $previous = null, ?int $ownerId = null): FuelEntryData
    {
        $text = $data->station === null ? '' : StationName::tidy($data->station);
        $name = $text === '' ? null : $text;

        if (!$this->features->isEnabled(Feature::Stations)) {
            $kept = $previous?->data->stationId !== null && $previous->data->station === $data->station
                ? $previous->data->stationId
                : null;

            return $data->withStation($kept, $data->station);
        }
        if ($data->grade === FuelGrade::Home) {
            return $data->withStation(null, $name);
        }

        if ($data->stationId !== null) {
            $station = $this->stations->resolve($data->stationId);
            if ($station !== null) {
                return $data->withStation($station->id, $station->data->name);
            }
        }
        if ($name === null) {
            return $data->withStation(null, null);
        }

        $station = $this->stations->findByName($name);
        if ($station === null) {
            $user = $this->author->user();
            $id = $this->stations->insert(
                new StationData(
                    name: mb_substr($name, 0, 100),
                    country: $user === null ? null : Region::of($user->preferences->locale),
                ),
                $user->id ?? $ownerId,
                $this->clock->now(),
            );

            return $data->withStation($id, mb_substr($name, 0, 100));
        }

        return $data->withStation($station->id, $station->data->name);
    }
}
