<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Logbook\Domain\FuelPrices\FeedPrice;
use Logbook\Domain\FuelPrices\FeedStation;
use Logbook\Domain\FuelPrices\PriceChange;
use Logbook\Repository\ListedPriceRepository;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Repository\StationRepository;
use Logbook\Service\Ai\SecretUnreadable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * One run of the `fuel_prices` job (spec.md §7.34 *Sync job*, decided
 * 2026-10-03, #142):
 *
 * 1. Full (the first run, no stations stored, a new provider, or a day
 *    since the last full one) or incremental (changes since the last good
 *    sync, less 10 minutes).
 * 2. Each page saved in its own transaction as it arrives, so a failure
 *    part-way keeps what was saved and never deletes current prices; the
 *    sync state moves only when the whole run succeeded.
 * 3. A full sync marks stations it didn't list as removed and drops the
 *    prices it didn't list.
 * 4. Tracked stations' listed prices into their history, linked stations'
 *    details refreshed, price alerts checked.
 */
final readonly class FuelPriceSync
{
    public const int MARGIN_MINUTES = 10;
    public const int FULL_EVERY_HOURS = 24;

    public function __construct(
        private FuelPriceConfig $config,
        private FuelPriceSecrets $secrets,
        private ProviderStationRepository $providerStations,
        private StationRepository $stations,
        private ListedPriceRepository $history,
        private LinkedStations $linked,
        private PriceAlertChecker $alerts,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param Closure(): bool $cancelled
     * @throws FeedFailure when the feed can't be read (what was saved stays)
     * @throws SecretUnreadable when the credentials can't be opened
     */
    public function run(BulkPriceProvider $provider, LoggerInterface $logger, Closure $cancelled): SyncOutcome
    {
        $code = $provider->code();
        $startedAt = $this->clock->now();
        $state = $this->config->syncState();
        $full = $this->needsFull($code, $state, $startedAt);
        $since = $full || $state->lastGood === null
            ? null
            : $state->lastGood->modify(sprintf('-%d minutes', self::MARGIN_MINUTES));
        $logger->info($full ? 'Full sync from {provider}.' : 'Changes from {provider} since {since}.', [
            'provider' => $code,
            'since' => $since?->format(DATE_ATOM) ?? '',
        ]);

        $credentials = $this->secrets->open($provider);
        $outcome = new SyncOutcome($full);
        $sink = new class ($this->providerStations, $this->connection, $code, $startedAt, $outcome) implements FeedSink {
            public function __construct(
                private ProviderStationRepository $repository,
                private Connection $connection,
                private string $provider,
                private DateTimeImmutable $now,
                private SyncOutcome $outcome,
            ) {
            }

            public function stations(array $stations): void
            {
                $counts = $this->connection->transactional(
                    fn (): array => $this->repository->upsertStations($this->provider, $stations, $this->now),
                );
                $this->outcome->added += $counts['added'];
                $this->outcome->updated += $counts['updated'];
                $this->outcome->removed += $counts['removed'];
                foreach ($stations as $station) {
                    /** @var FeedStation $station */
                    $this->outcome->seen[$station->ref] = true;
                }
            }

            public function prices(array $prices): void
            {
                $counts = $this->connection->transactional(
                    fn (): array => $this->repository->upsertPrices($this->provider, $prices, $this->now),
                );
                $this->outcome->prices += $counts['saved'];
                $this->outcome->changed += $counts['changed'];
                $this->outcome->unknownStations += $counts['unknown'];
            }
        };

        $outcome->report = $provider->sync(
            $credentials,
            $provider->gradeMap($this->config->settings()->chosenGrades()),
            $since,
            $sink,
            $cancelled,
        );

        // A full list that came back empty is a feed fault, not every station closing.
        if ($full && $outcome->seen !== []) {
            $outcome->removed += $this->providerStations->markMissingRemoved($code, $outcome->seen, $startedAt);
            $this->providerStations->dropPricesNotSyncedSince($code, $startedAt);
        }
        $this->config->saveSyncState(new SyncState(
            $code,
            $startedAt,
            $full ? $startedAt : ($state->provider === $code ? $state->lastFull : null),
        ));

        $outcome->recorded = $this->recordHistory($code);
        $outcome->refreshed = $this->linked->refreshAll($code, $startedAt);
        $alerts = $this->alerts->check($provider, $this->clock->now());
        $outcome->alertsSent = $alerts['sent'];

        return $outcome;
    }

    public function needsFull(string $code, SyncState $state, DateTimeImmutable $now): bool
    {
        return $state->provider !== $code
            || $state->lastGood === null
            || $state->lastFull === null
            || $this->providerStations->count($code, true) === 0
            || $now->getTimestamp() - $state->lastFull->getTimestamp() >= self::FULL_EVERY_HOURS * 3600;
    }

    /**
     * Each tracked station's current prices into its history (spec.md
     * §7.34 *History*): the same report twice is kept once.
     */
    private function recordHistory(string $code): int
    {
        $refs = $this->stations->trackedRefs($code);
        if ($refs === []) {
            return 0;
        }
        $sources = $this->providerStations->findByRefs($code, $refs);
        $prices = $this->providerStations->prices(array_values(array_map(static fn ($s): int => $s->id, $sources)));
        $recorded = 0;
        foreach ($sources as $ref => $source) {
            foreach ($prices[$source->id] ?? [] as $listed) {
                if ($this->history->record($code, $ref, new PriceChange($listed->grade, $listed->price, $listed->reportedAt))) {
                    $recorded++;
                }
            }
        }

        return $recorded;
    }
}
