<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\FuelPrices\ProviderStation;
use Logbook\Domain\FuelPrices\StationLink;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\Station\StationName;
use Logbook\Domain\User\User;
use Logbook\Repository\PriceAlertRepository;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Repository\StationRepository;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Geo\Haversine;
use Logbook\Support\I18n\Region;
use Psr\Clock\ClockInterface;

/**
 * Links Logbook stations to provider stations (spec.md §7.34 *Linking
 * stations*): candidates within 150 m, best name match first (by postcode,
 * then name, without a position); link, unlink, *Keep my details*; and a
 * new station from a provider station in one tap.
 */
final readonly class StationLinker
{
    public const float RADIUS_KM = 0.15;
    public const int MAX_CANDIDATES = 10;

    public function __construct(
        private FuelPriceConfig $config,
        private ProviderStationRepository $providerStations,
        private StationRepository $stations,
        private PriceAlertRepository $alerts,
        private LinkedStations $linked,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<LinkCandidate> best first; none while prices are off
     */
    public function candidates(Station $station): array
    {
        $provider = $this->config->provider();
        if ($provider === null) {
            return [];
        }
        $code = $provider->code();
        $data = $station->data;
        $found = [];
        if ($data->hasPosition()) {
            $hits = $this->providerStations->nearby($code, (float) $data->latitude, (float) $data->longitude, self::RADIUS_KM);
            foreach ($hits as $hit) {
                $found[] = new LinkCandidate($hit['station'], $hit['km'], self::similarity($data, $hit['station']));
            }
        } else {
            $seen = [];
            $byPostcode = $data->postcode === null ? [] : $this->providerStations->byPostcode($code, $data->postcode);
            foreach ([...$byPostcode, ...$this->providerStations->searchByName($code, self::searchText($data))] as $candidate) {
                if (!isset($seen[$candidate->id])) {
                    $seen[$candidate->id] = true;
                    $found[] = new LinkCandidate($candidate, null, self::similarity($data, $candidate));
                }
            }
        }

        // Linked to another Logbook station already: not offered.
        $taken = [];
        foreach ($this->stations->linked($code) as $other) {
            if ($other->id !== $station->id && $other->link !== null) {
                $taken[$other->link->ref] = true;
            }
        }
        $found = array_values(array_filter($found, static fn (LinkCandidate $c): bool => !isset($taken[$c->station->ref()])));
        usort($found, static fn (LinkCandidate $a, LinkCandidate $b): int => $b->similarity <=> $a->similarity
            ?: ($a->km ?? INF) <=> ($b->km ?? INF));

        return array_slice($found, 0, self::MAX_CANDIDATES);
    }

    /**
     * @throws LinkRefused when the provider station is unknown or linked to another station
     */
    public function link(Station $station, string $ref): Station
    {
        $provider = $this->config->provider() ?? throw new LinkRefused('off');
        $source = $this->providerStations->findByRef($provider->code(), $ref) ?? throw new LinkRefused('unknown');
        $other = $this->stations->findByLink($provider->code(), $ref);
        if ($other !== null && $other->id !== $station->id) {
            throw new LinkRefused('taken');
        }
        $now = $this->clock->now();
        $keep = $station->link !== null && $station->link->keepMyDetails;
        $this->transaction->run(function () use ($station, $provider, $ref, $keep, $now): void {
            $this->stations->setLink($station->id, new StationLink($provider->code(), $ref, $keep), $now);
        });
        $linked = $this->reload($station);
        $this->linked->refresh($linked, $source, $now);

        return $this->reload($station);
    }

    /**
     * Unlink: the station keeps its details, and every alert on it goes.
     */
    public function unlink(Station $station): Station
    {
        $this->transaction->run(function () use ($station): void {
            $this->stations->setLink($station->id, null, $this->clock->now());
            $this->alerts->deleteForStation($station->id);
        });

        return $this->reload($station);
    }

    public function keepMyDetails(Station $station, bool $keep): Station
    {
        if ($station->link === null) {
            return $station;
        }
        $now = $this->clock->now();
        $this->stations->setLink($station->id, new StationLink($station->link->provider, $station->link->ref, $keep), $now);
        $reloaded = $this->reload($station);
        if (!$keep) {
            $source = $this->providerStations->findByRef($station->link->provider, $station->link->ref);
            if ($source !== null && $this->linked->refresh($reloaded, $source, $now)) {
                $reloaded = $this->reload($station);
            }
        }

        return $reloaded;
    }

    /**
     * *Add station* from a result (spec.md §7.34): its details copied and
     * linked. A station of that name without a link is linked instead when
     * it has no position or is within 150 m; a name in use otherwise gets
     * the postcode added ("Tesco (BT41 4LD)").
     *
     * @throws LinkRefused when the provider station is unknown or already linked
     */
    public function addFromProvider(User $user, string $ref): Station
    {
        $provider = $this->config->provider() ?? throw new LinkRefused('off');
        $source = $this->providerStations->findByRef($provider->code(), $ref) ?? throw new LinkRefused('unknown');
        $already = $this->stations->findByLink($provider->code(), $ref);
        if ($already !== null) {
            return $already;
        }
        $feed = $source->data;
        foreach ([$feed->name, $feed->postcode === null ? null : sprintf('%s (%s)', $feed->name, $feed->postcode)] as $name) {
            if ($name === null) {
                continue;
            }
            $name = mb_substr(StationName::tidy($name), 0, 100);
            $existing = $this->stations->findByName($name);
            if ($existing === null) {
                $id = $this->transaction->run(function () use ($user, $source, $name, $provider): int {
                    $now = $this->clock->now();
                    $id = $this->stations->insert(LinkedStations::merged(new StationData(
                        name: $name,
                        brand: $source->data->brand === null ? null : mb_substr($source->data->brand, 0, 50),
                        country: Region::of($user->preferences->locale),
                    ), $source), $user->id, $now);
                    $this->stations->setLink($id, new StationLink($provider->code(), $source->ref()), $now);

                    return $id;
                });

                return $this->stations->find((int) $id) ?? throw new LinkRefused('unknown');
            }
            if ($existing->link === null && $this->near($existing, $source)) {
                return $this->link($existing, $ref);
            }
        }

        throw new LinkRefused('taken');
    }

    private function near(Station $station, ProviderStation $source): bool
    {
        if (!$station->data->hasPosition() || !$source->data->hasPosition()) {
            return !$station->data->hasPosition();
        }

        return Haversine::km(
            (float) $station->data->latitude,
            (float) $station->data->longitude,
            (float) $source->data->latitude,
            (float) $source->data->longitude,
        ) <= self::RADIUS_KM;
    }

    private function reload(Station $station): Station
    {
        return $this->stations->find($station->id) ?? $station;
    }

    /**
     * How alike two stations' names are (0–100), the brand counted as part
     * of the name, on normalised names.
     */
    public static function similarity(StationData $data, ProviderStation $candidate): float
    {
        $mine = StationName::normalise(trim(($data->brand ?? '') . ' ' . $data->name));
        $theirs = StationName::normalise(trim(($candidate->data->brand ?? '') . ' ' . $candidate->data->name));
        if ($mine === '' || $theirs === '') {
            return 0.0;
        }
        similar_text($mine, $theirs, $percent);
        $plain = StationName::normalise($data->name);
        $theirName = StationName::normalise($candidate->data->name);
        similar_text($plain, $theirName, $byName);

        return max($percent, $byName);
    }

    private static function searchText(StationData $data): string
    {
        return explode(' ', StationName::normalise($data->name))[0];
    }
}
