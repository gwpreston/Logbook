<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\Station\StationName;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Database\Transaction;
use Logbook\Support\I18n\Region;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;

/**
 * Fuel stations (spec.md §7.33): shared records, each user's favourites,
 * and what they paid at each, from the fill-ups on the vehicles they can
 * see. Any user adds and favourites stations; only the creator or an admin
 * edits and merges them (decided 2026-10-02, #132).
 */
final readonly class StationService
{
    /** How many stations the fill-up form's combo box offers at once. */
    public const int CHOICES = 20;

    public function __construct(
        private StationRepository $stations,
        private FuelEntryRepository $entries,
        private VehicleRepository $vehicleRows,
        private VehicleAccess $access,
        private EntryAccess $entryAccess,
        private VehicleService $vehicles,
        private FeatureToggles $features,
        private Transaction $transaction,
        private ClockInterface $clock,
    ) {
    }

    public function enabled(): bool
    {
        return $this->features->isEnabled(Feature::Stations);
    }

    /**
     * The station, or null when it doesn't exist. A merged station is
     * returned as it is; resolve() gives the one it became.
     */
    public function find(int $id): ?Station
    {
        return $this->stations->find($id);
    }

    public function resolve(int $id): ?Station
    {
        return $this->stations->resolve($id);
    }

    /**
     * @param list<int> $ids
     * @return array<int, Station>
     */
    public function findMany(array $ids): array
    {
        return $this->stations->findMany($ids);
    }

    public function canEdit(User $user, Station $station): bool
    {
        return $user->isAdmin || ($station->createdBy !== null && $station->createdBy === $user->id);
    }

    /**
     * Every fill-up linked to a station on the vehicles the user can see.
     *
     * @param list<int>|null $stationIds null = any station
     * @param list<int>|null $vehicleIds limit to these of the visible vehicles; null = all of them
     * @return list<StationVisit>
     */
    public function visits(User $user, ?array $stationIds = null, ?array $vehicleIds = null): array
    {
        $visible = $this->access->visibleVehicleIds($user, VehicleScope::All);
        if ($vehicleIds !== null) {
            $visible = array_values(array_intersect($visible, $vehicleIds));
        }
        $entries = $this->entries->listLinked($visible, $stationIds);
        if ($entries === []) {
            return [];
        }

        $vehicles = [];
        $currencies = [];
        $ids = array_values(array_unique(array_map(static fn (FuelEntry $entry): int => $entry->vehicleId, $entries)));
        foreach ($this->vehicleRows->listByIds($ids) as $vehicle) {
            $vehicles[$vehicle->id] = $vehicle;
            $currencies[$vehicle->id] = $this->vehicles->currencyFor($user, $vehicle);
        }

        $visits = [];
        foreach ($entries as $entry) {
            $vehicle = $vehicles[$entry->vehicleId] ?? null;
            if (!$vehicle instanceof Vehicle) {
                continue;
            }
            $visits[] = new StationVisit(
                $entry,
                $currencies[$vehicle->id],
                $this->entryAccess->canSeeAmount($user, $vehicle, $entry->createdBy),
            );
        }

        return $visits;
    }

    /**
     * @param list<int>|null $stationIds
     * @return array<int, StationSummary> keyed by station id
     */
    public function summaries(User $user, ?array $stationIds = null, ?DateTimeImmutable $since = null): array
    {
        return StationStats::summarise($this->visits($user, $stationIds), $since);
    }

    /**
     * The stations list (spec.md §7.33): favourites first, then by last
     * visit, then by name; with a search, only the matches.
     *
     * @return list<StationListing>
     */
    public function listing(User $user, string $query = ''): array
    {
        $stations = $this->stations->search($query);
        $favourites = array_flip($this->stations->favouriteIds($user->id));
        $visits = $this->visits($user);
        $all = StationStats::summarise($visits);
        $recent = StationStats::summarise($visits, $this->yearAgo());

        $rows = array_map(
            static fn (Station $station): StationListing => new StationListing(
                $station,
                isset($favourites[$station->id]),
                $all[$station->id] ?? null,
                $recent[$station->id] ?? null,
            ),
            $stations,
        );
        usort($rows, self::order(...));

        return $rows;
    }

    /**
     * What the fill-up form's combo box offers for what was typed:
     * favourites first, then stations the user used recently (newest
     * first), then the rest by name. Merged stations never appear.
     *
     * @return list<StationListing>
     */
    public function choices(User $user, string $query = '', int $limit = self::CHOICES): array
    {
        $rows = $this->listing($user, $query);
        if ($query === '') {
            // Without a search, only favourites and stations used before.
            $rows = array_values(array_filter(
                $rows,
                static fn (StationListing $row): bool => $row->favourite || $row->summary !== null,
            ));
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * The user's latest fill-up at the station whose amounts they may see,
     * for "Last time here" (spec.md §7.33; a hint, never prefilled).
     */
    public function lastTimeHere(User $user, int $stationId): ?StationVisit
    {
        $last = null;
        foreach ($this->visits($user, [$stationId]) as $visit) {
            if ($visit->amountVisible) {
                $last = $visit;
            }
        }

        return $last;
    }

    /**
     * The stations of a vehicle with the most spend in the last 12 months,
     * for the Fuel tab's *By station* card.
     *
     * @return list<array{station: Station, summary: StationSummary}>
     */
    public function topForVehicle(User $user, Vehicle $vehicle, int $count = 5): array
    {
        $summaries = StationStats::summarise($this->visits($user, null, [$vehicle->id]), $this->yearAgo());
        // One vehicle, one currency.
        $spend = static fn (StationSummary $summary): string => array_values($summary->spend())[0] ?? '0';
        uasort($summaries, static fn (StationSummary $a, StationSummary $b): int => Decimal::compare($spend($b), $spend($a))
            ?: $b->visits <=> $a->visits);
        $top = array_slice($summaries, 0, $count, true);
        $stations = $this->stations->findMany(array_keys($top));
        $rows = [];
        foreach ($top as $id => $summary) {
            if (isset($stations[$id])) {
                $rows[] = ['station' => $stations[$id], 'summary' => $summary];
            }
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    public function favouriteIds(User $user): array
    {
        return $this->stations->favouriteIds($user->id);
    }

    public function isFavourite(User $user, Station $station): bool
    {
        return in_array($station->id, $this->stations->favouriteIds($user->id), true);
    }

    public function setFavourite(User $user, Station $station, bool $favourite): void
    {
        $this->stations->setFavourite($user->id, $station->id, $favourite, $this->clock->now());
    }

    /**
     * Add a station; one with the same normalised name is returned instead.
     */
    public function create(User $user, StationData $data): Station
    {
        $existing = $this->stations->findByName($data->name);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->stations->insert($data, $user->id, $this->clock->now());

        return $this->stations->find($id) ?? throw new StationNotFound(sprintf('Station %d not found.', $id));
    }

    /**
     * The station a typed name stands for, created when there is none (the
     * fill-up form's *Add*, CSV import, the API, scans and drafts).
     */
    public function linkOrCreate(string $name, ?int $authorId, ?string $locale): Station
    {
        $tidy = StationName::tidy($name);
        $existing = $this->stations->findByName($tidy);
        if ($existing !== null) {
            return $existing;
        }
        $data = new StationData(name: mb_substr($tidy, 0, 100), country: $locale === null ? null : Region::of($locale));
        $id = $this->stations->insert($data, $authorId, $this->clock->now());

        return $this->stations->find($id) ?? throw new StationNotFound(sprintf('Station %d not found.', $id));
    }

    /**
     * Whether a typed name would link an existing station (CSV preview,
     * scans and drafts: "new station: Tesco Antrim" otherwise).
     */
    public function existing(string $name): ?Station
    {
        return $this->stations->findByName($name);
    }

    /**
     * @throws StationNameTaken when another station has the normalised name
     */
    public function update(Station $station, StationData $data): Station
    {
        $other = $this->stations->findByName($data->name);
        if ($other !== null && $other->id !== $station->id) {
            throw new StationNameTaken($other);
        }
        $this->transaction->run(function () use ($station, $data): void {
            $this->stations->update($station->id, $data, $this->clock->now());
            $this->entries->renameStation($station->id, $data->name);
        });

        return $this->stations->find($station->id) ?? throw new StationNotFound(sprintf('Station %d not found.', $station->id));
    }

    /**
     * Merge $other into $keep (spec.md §7.33 *Merge*), with $data the
     * details chosen for the kept station. One transaction.
     */
    public function merge(Station $keep, Station $other, StationData $data): Station
    {
        if ($keep->id === $other->id || $keep->isMerged() || $other->isMerged()) {
            throw new StationNotFound('Both stations must exist, differ and be unmerged.');
        }
        $this->transaction->run(function () use ($keep, $other, $data): void {
            $now = $this->clock->now();
            $this->stations->merge($other->id, $keep->id, $now);
            $this->stations->update($keep->id, $data, $now);
            $this->entries->renameStation($keep->id, $data->name);
        });

        return $this->stations->find($keep->id) ?? throw new StationNotFound(sprintf('Station %d not found.', $keep->id));
    }

    /**
     * @return list<DuplicatePair>
     */
    public function duplicates(): array
    {
        return Duplicates::find($this->stations->listActive());
    }

    /**
     * The details a merge starts from: the kept station's, with the other's
     * where the kept one has none, and the grades of both.
     */
    public static function mergedData(Station $keep, Station $other): StationData
    {
        $a = $keep->data;
        $b = $other->data;
        $position = $a->hasPosition() ? [$a->latitude, $a->longitude] : [$b->latitude, $b->longitude];
        $grades = [];
        foreach ([...$a->grades, ...$b->grades] as $grade) {
            $grades[$grade->value] = $grade;
        }

        return new StationData(
            name: $a->name,
            brand: $a->brand ?? $b->brand,
            address: $a->address ?? $b->address,
            postcode: $a->postcode ?? $b->postcode,
            country: $a->country ?? $b->country,
            latitude: $position[0],
            longitude: $position[1],
            grades: array_values(array_filter(
                FuelGrade::cases(),
                static fn (FuelGrade $grade): bool => isset($grades[$grade->value]),
            )),
            openingHours: $a->openingHours ?? $b->openingHours,
            notes: $a->notes ?? $b->notes,
        );
    }

    public function yearAgo(): DateTimeImmutable
    {
        return $this->clock->now()->modify('-12 months');
    }

    private static function order(StationListing $a, StationListing $b): int
    {
        $lastA = $a->summary?->lastVisit;
        $lastB = $b->summary?->lastVisit;

        return [$b->favourite, $lastB !== null, $lastB, StationName::normalise($a->station->data->name), $a->station->id]
            <=> [$a->favourite, $lastA !== null, $lastA, StationName::normalise($b->station->data->name), $b->station->id];
    }
}
