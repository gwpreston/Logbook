<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\StationLink;
use Logbook\Domain\Job\JobStatus;
use Logbook\Repository\ListedPriceRepository;
use Logbook\Repository\ProviderStationRepository;
use Logbook\Repository\StationRepository;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\FuelPricesJob;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
use Logbook\Support\Config\AppSettings;
use Logbook\Service\Jobs\CleanupJob;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Domain\Job\JobTrigger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The `fuel_prices` job against UK Fuel Finder (spec.md §7.34 *Sync job*,
 * decided 2026-10-03, #140–#144): a full sync on the first run, then
 * changes only, with a daily full one that alone marks stations removed;
 * a failure keeps the current prices; tracked stations' price changes
 * kept and trimmed; linked stations kept up to date.
 */
final class FuelPriceSyncTest extends FuelPricesTestCase
{
    public function testTheFirstRunIsAFullSyncOfStationsAndPrices(): void
    {
        [$app] = $this->pricesApp();

        $run = $this->sync($app);

        self::assertSame(JobStatus::Ok, $run->status, (string) $run->summary);
        self::assertStringStartsWith('Full sync: 5 stations, 10 prices, 1 removed.', (string) $run->summary);
        self::assertStringContainsString('2 implausible prices skipped', (string) $run->summary);

        // One token, then the stations and the prices, each from batch 1 (fewer than 500: the last).
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(
            ['client_id' => 'client-id-1234', 'client_secret' => 'client-secret-5678'],
            json_decode(self::text($this->requests[0]['options']['body'] ?? ''), true),
        );
        self::assertSame([['batch-number' => '1']], $this->queries('/api/v1/pfs'));
        self::assertSame([['batch-number' => '1']], $this->queries('/api/v1/pfs/fuel-prices'));
        $authorization = self::headers($this->requests[1]['options'])['authorization'] ?? [];
        self::assertIsArray($authorization);
        self::assertContains('Authorization: Bearer ' . self::TOKEN, $authorization);
        self::assertCount(3, $this->requests);

        $repository = $this->service($app, ProviderStationRepository::class);
        self::assertSame(4, $repository->count(FuelFinderProvider::CODE), 'the permanently closed one is removed');
        self::assertSame(5, $repository->count(FuelFinderProvider::CODE, true));
        $maxol = $repository->findByRef(FuelFinderProvider::CODE, self::ref('antrim-maxol'));
        self::assertNotNull($maxol);
        self::assertTrue($maxol->data->temporarilyClosed);
        self::assertFalse($maxol->isOpen());
        self::assertTrue($repository->findByRef(FuelFinderProvider::CODE, self::ref('larne-closed'))?->isRemoved());

        $tesco = $repository->findByRef(FuelFinderProvider::CODE, self::ref('antrim-tesco'));
        self::assertNotNull($tesco);
        $prices = $repository->prices([$tesco->id])[$tesco->id];
        self::assertEqualsCanonicalizing(['e10_95', 'e5_97', 'b7'], array_keys($prices));
        self::assertSame('1.359', $prices['e10_95']->price);
        self::assertEquals(new DateTimeImmutable('2026-10-03T06:15:00Z'), $prices['e10_95']->reportedAt);
        self::assertSame(10, $repository->countPrices(FuelFinderProvider::CODE));
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private static function headers(array $options): array
    {
        $headers = $options['normalized_headers'] ?? [];

        return is_array($headers) ? $headers : [];
    }

    public function testLaterRunsAskForChangesAndOnlyADailyFullSyncRemoves(): void
    {
        [$app] = $this->pricesApp();
        $this->sync($app);
        $repository = $this->service($app, ProviderStationRepository::class);

        // An hour later: only what changed since the last good sync, less 10 minutes.
        $this->clock->set(new DateTimeImmutable('2026-10-03T08:00:00Z'));
        $this->requests = [];
        $this->stations = [];
        $this->prices = [[
            'node_id' => self::ref('antrim-tesco'),
            'fuel_prices' => [['price' => '0134.9000', 'fuel_type' => 'E10', 'price_last_updated' => '2026-10-03T07:45:00']],
        ]];
        $run = $this->sync($app);

        self::assertSame(JobStatus::Ok, $run->status, (string) $run->summary);
        self::assertSame(
            [['batch-number' => '1', 'effective-start-timestamp' => '2026-10-03 06:50:00']],
            $this->queries('/api/v1/pfs/fuel-prices'),
        );
        $tesco = $repository->findByRef(FuelFinderProvider::CODE, self::ref('antrim-tesco'));
        self::assertNotNull($tesco);
        $prices = $repository->prices([$tesco->id])[$tesco->id];
        self::assertSame('1.349', $prices['e10_95']->price);
        self::assertSame('1.499', $prices['e5_97']->price, 'a grade missing from a change list is kept');
        self::assertSame(4, $repository->count(FuelFinderProvider::CODE), 'an incremental sync removes nothing');

        // A day after the full sync: everything again; Shell has gone from the feed.
        $this->clock->set(new DateTimeImmutable('2026-10-04T07:30:00Z'));
        $this->requests = [];
        $this->stations = array_values(array_filter(
            self::fixture('pfs-page-1.json'),
            static fn (mixed $s): bool => is_array($s) && ($s['node_id'] ?? '') !== self::ref('antrim-shell'),
        ));
        $this->prices = null;
        $run = $this->sync($app);

        self::assertStringStartsWith('Full sync', (string) $run->summary);
        self::assertSame([['batch-number' => '1']], $this->queries('/api/v1/pfs'));
        $shell = $repository->findByRef(FuelFinderProvider::CODE, self::ref('antrim-shell'));
        self::assertNotNull($shell);
        self::assertTrue($shell->isRemoved());
        self::assertSame(3, $repository->count(FuelFinderProvider::CODE));
    }

    public function testAFailedSyncKeepsTheCurrentPricesAndTriesAgainFromTheSamePoint(): void
    {
        [$app] = $this->pricesApp();
        $this->sync($app);
        $repository = $this->service($app, ProviderStationRepository::class);
        $before = $this->service($app, FuelPriceConfig::class)->syncState();

        $this->clock->set(new DateTimeImmutable('2026-10-03T08:00:00Z'));
        $this->answer = static fn (string $method, string $url): ?MockResponse => str_contains($url, 'fuel-prices')
            ? new MockResponse('oops', ['http_code' => 503])
            : null;
        $run = $this->sync($app);

        self::assertSame(JobStatus::Failed, $run->status);
        self::assertSame('The provider answered with HTTP 503.', $run->summary);
        self::assertSame(10, $repository->countPrices(FuelFinderProvider::CODE), 'never deletes current prices');
        self::assertEquals(
            $before,
            $this->service($app, FuelPriceConfig::class)->syncState(),
            'the next run asks from the same point',
        );
    }

    public function testRefusedCredentialsAndRateLimitsAreReported(): void
    {
        [$app] = $this->pricesApp();
        $this->answer = static fn (string $method): ?MockResponse => $method === 'POST'
            ? new MockResponse('{"success":false}', ['http_code' => 401])
            : null;
        self::assertSame('The provider refused the credentials. Check the client ID and secret.', $this->sync($app)->summary);

        $this->answer = static fn (string $method): ?MockResponse => $method === 'GET'
            ? new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 1']])
            : null;
        $this->requests = [];
        $run = $this->sync($app);
        self::assertSame(JobStatus::Failed, $run->status);
        self::assertSame('The provider asked Logbook to slow down. The next sync will try again.', $run->summary);
        self::assertCount(5, $this->requests, 'a token, then the first page and three retries');
    }

    public function testOffByDefaultTheJobIsNeverDueAndFetchesNothing(): void
    {
        [$app] = $this->pricesApp(enable: false);
        $job = $this->service($app, FuelPricesJob::class);

        self::assertNull($job->interval());
        $run = $this->sync($app);
        self::assertSame(JobStatus::Ok, $run->status);
        self::assertSame('Fuel prices are off; nothing fetched.', $run->summary);
        self::assertSame([], $this->requests);

        $this->enable($app);
        self::assertSame(3600, $job->interval(), 'every 60 minutes by default');
    }

    public function testTrackedStationsKeepTheirPriceChangesForTheRetentionPeriod(): void
    {
        [$app] = $this->pricesApp();
        $stations = $this->service($app, StationRepository::class);
        $now = new DateTimeImmutable(self::NOW);
        $used = $this->station($app, 'Tesco Antrim');
        $favourite = $this->station($app, 'Shell Antrim');
        $idle = $this->station($app, 'Maxol Antrim');
        $stations->setLink($used->id, new StationLink(FuelFinderProvider::CODE, self::ref('antrim-tesco')), $now);
        $stations->setLink($favourite->id, new StationLink(FuelFinderProvider::CODE, self::ref('antrim-shell')), $now);
        $stations->setLink($idle->id, new StationLink(FuelFinderProvider::CODE, self::ref('antrim-maxol')), $now);
        $owner = $this->owner($app);
        $golf = $this->vehicle($app);
        $entry = $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '1000', '40', '56.00', grade: FuelGrade::E10_95);
        $this->connection($app)->update('fuel_entries', ['station_id' => $used->id], ['id' => $entry->id]);
        $stations->setFavourite($owner->id, $favourite->id, true, $now);

        $this->sync($app);
        $this->sync($app);

        $history = $this->service($app, ListedPriceRepository::class);
        self::assertCount(
            3,
            $history->changes(FuelFinderProvider::CODE, self::ref('antrim-tesco')),
            'used: its three prices, once',
        );
        self::assertCount(3, $history->changes(FuelFinderProvider::CODE, self::ref('antrim-shell')), 'favourited');
        self::assertSame(
            [],
            $history->changes(FuelFinderProvider::CODE, self::ref('antrim-maxol')),
            'linked but neither used nor favourited',
        );

        // Kept for PRICE_HISTORY_DAYS (1,095 by default), then the cleanup job drops them.
        self::assertSame(1095, $this->service($app, AppSettings::class)->priceHistoryDays);
        $this->clock->set(new DateTimeImmutable('2029-10-05T07:00:00Z'));
        $this->service($app, JobRunner::class)->run($this->service($app, CleanupJob::class), JobTrigger::Manual);
        self::assertSame([], $history->changes(FuelFinderProvider::CODE, self::ref('antrim-tesco')));
    }

    public function testLinkedStationsFollowTheFeedUnlessTheirDetailsAreKept(): void
    {
        [$app] = $this->pricesApp();
        $stations = $this->service($app, StationRepository::class);
        $now = new DateTimeImmutable(self::NOW);
        $followed = $this->station($app, 'Tesco Antrim');
        $kept = $this->station($app, 'My Shell', '54.700000', '-6.200000');
        $stations->setLink($followed->id, new StationLink(FuelFinderProvider::CODE, self::ref('antrim-tesco')), $now);
        $stations->setLink(
            $kept->id,
            new StationLink(FuelFinderProvider::CODE, self::ref('antrim-shell'), keepMyDetails: true),
            $now,
        );

        $this->sync($app);

        $followed = $stations->find($followed->id);
        self::assertNotNull($followed);
        self::assertSame('Tesco Antrim', $followed->data->name, 'the name is never changed');
        self::assertSame('BT41 4LD', $followed->data->postcode);
        self::assertSame('54.718012', $followed->data->latitude);
        self::assertSame('Mo-Su 06:00-23:00', $followed->data->openingHours);
        self::assertSame([FuelGrade::E10_95, FuelGrade::E5_97, FuelGrade::B7], $followed->data->grades);

        $kept = $stations->find($kept->id);
        self::assertNotNull($kept);
        self::assertSame('54.700000', $kept->data->latitude, 'Keep my details');
        self::assertNull($kept->data->postcode);
    }
}
