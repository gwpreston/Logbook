<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use DateTimeImmutable;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Repository\MotTestRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Jobs\JobContext;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobResult;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotHistoryJob;
use Logbook\Service\MotHistory\MotHistoryStatus;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The `mot_history` job (spec.md §7.38 *Refresh*): only vehicles whose MOT
 * falls due between 14 days ahead and 60 days ago, not fetched in 7 days;
 * one sign-in per run; stops at a 429; a new car by its first MOT due date
 * (#339); the keep-alive after 80 idle days or none ever (#327, #342).
 * The fixture's latest expiry is 13 Mar 2027.
 */
final class MotHistoryJobTest extends MotHistoryTestCase
{
    public function testItIsRegisteredAndDueDailyOnlyWhileEnabled(): void
    {
        $this->start(false);
        $job = $this->service($this->app, MotHistoryJob::class);
        self::assertSame($job, $this->service($this->app, JobRegistry::class)->get(MotHistoryJob::NAME));
        self::assertNull($job->interval());
        $result = $this->runJob();
        self::assertSame(JobStatus::Ok, $result->status);
        self::assertSame('MOT history is off; nothing sent.', $result->summary);
        self::assertSame([], $this->requests);

        $this->start();
        self::assertSame(86400, $this->service($this->app, MotHistoryJob::class)->interval());
    }

    public function testItRefreshesAVehicleInTheWindowWithOneSignIn(): void
    {
        $this->start();
        $golf = $this->golf();
        $polo = $this->golf('CD34 EFG', model: 'Polo');
        $this->fetch($golf);
        $this->fetch($polo);
        $this->requests = [];

        $this->clock->set(new DateTimeImmutable('2027-02-27T09:00:00Z'));
        $result = $this->runJob();

        self::assertSame(JobStatus::Ok, $result->status, $result->summary);
        self::assertSame(2, $result->count('refreshed'));
        self::assertSame(0, $result->count('pinged'), 'a run that fetched needs no keep-alive');
        $signIns = array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], 'microsoftonline'));
        self::assertCount(1, $signIns);
        self::assertCount(2, $this->vehicleRequests());
        self::assertSame('2 vehicles due: 2 refreshed, no new tests.', $result->summary);
        $state = $this->service($this->app, MotTestRepository::class)->state($golf->id);
        self::assertSame('2027-02-27', $state->fetchedAt?->format('Y-m-d'));

        // Fetched in the last 7 days: not again.
        $this->requests = [];
        $this->clock->set(new DateTimeImmutable('2027-03-05T09:00:00Z'));
        self::assertSame(0, $this->runJob()->count('due'));
        self::assertSame([], $this->requests);
        // A week on, even a little earlier in the day, it is due again.
        $this->clock->set(new DateTimeImmutable('2027-03-06T08:59:00Z'));
        self::assertSame(2, $this->runJob()->count('refreshed'));
    }

    public function testTheWindowRunsFromFourteenDaysBeforeToSixtyAfter(): void
    {
        $due = new DateTimeImmutable('2027-03-13');
        self::assertFalse(MotHistoryJob::inWindow($due, new DateTimeImmutable('2027-02-26')));
        self::assertTrue(MotHistoryJob::inWindow($due, new DateTimeImmutable('2027-02-27')));
        self::assertTrue(MotHistoryJob::inWindow($due, new DateTimeImmutable('2027-05-12')));
        self::assertFalse(MotHistoryJob::inWindow($due, new DateTimeImmutable('2027-05-13')));
    }

    public function testOutsideTheWindowArchivedOrUnconfirmedNothingIsSent(): void
    {
        $this->start();
        $golf = $this->golf();
        $this->fetch($golf);
        $archived = $this->golf('CD34 EFG');
        $this->fetch($archived);
        $this->service($this->app, VehicleRepository::class)
            ->setStatus($this->owner->id, $archived->id, VehicleStatus::Archived, new DateTimeImmutable(self::NOW));
        $this->golf('EF56 GHI'); // never confirmed
        $this->requests = [];

        $this->clock->set(new DateTimeImmutable('2027-02-26T09:00:00Z'));
        $before = $this->runJob();
        $this->clock->set(new DateTimeImmutable('2027-05-13T09:00:00Z'));
        $after = $this->runJob();
        self::assertSame([0, 0], [$before->count('due'), $after->count('due')]);
        $this->requests = [];
        $this->clock->set(new DateTimeImmutable('2027-03-01T09:00:00Z'));
        $result = $this->runJob();
        self::assertSame(1, $result->count('due'), 'only the confirmed, active vehicle');
        self::assertCount(1, $this->vehicleRequests());
        self::assertStringContainsString('AB12CDE', $this->vehicleRequests()[0]);
    }

    public function testANewCarIsRefreshedByItsFirstMotDueDate(): void
    {
        $this->start();
        $this->answer = static fn (string $url): ?MockResponse => str_contains($url, 'XY25ABC')
            ? new MockResponse((string) file_get_contents(self::FIXTURES . 'new-vehicle.json'))
            : null;
        $fiesta = $this->golf('XY25 ABC', make: 'Ford', model: 'Fiesta');
        $this->fetch($fiesta);
        $state = $this->service($this->app, MotTestRepository::class)->state($fiesta->id);
        self::assertSame('2028-03-13', $state->firstDueOn?->format('Y-m-d'));
        $this->requests = [];

        $this->clock->set(new DateTimeImmutable('2028-02-28T09:00:00Z'));
        self::assertSame(1, $this->runJob()->count('refreshed'), '#339: the first MOT due date opens the window');
    }

    public function testItStopsAtThrottlingAndTheRestWait(): void
    {
        $this->start();
        $golf = $this->golf();
        $polo = $this->golf('CD34 EFG', model: 'Polo');
        $this->fetch($golf);
        $this->fetch($polo);
        $this->requests = [];
        $this->answer = static fn (string $url): MockResponse => new MockResponse('{}', ['http_code' => 429]);

        $this->clock->set(new DateTimeImmutable('2027-03-01T09:00:00Z'));
        $result = $this->runJob();

        self::assertSame(JobStatus::Partial, $result->status);
        self::assertSame(0, $result->count('refreshed'));
        self::assertCount(1, $this->vehicleRequests(), 'the second vehicle is not asked');
        self::assertStringContainsString('DVSA is busy', $result->summary);
        $tests = $this->service($this->app, MotTestRepository::class);
        self::assertSame('2026-10-08', $tests->state($polo->id)->fetchedAt?->format('Y-m-d'), 'it waits for the next run');
        self::assertSame('rate_limited', $this->service($this->app, MotHistoryConfig::class)->status()->error?->value);
    }

    public function testRefusedCredentialsFailTheRun(): void
    {
        $this->start();
        $this->fetch($this->golf());
        $this->answer = static fn (string $url): MockResponse => new MockResponse('{}', ['http_code' => 403]);

        $this->clock->set(new DateTimeImmutable('2027-03-01T09:00:00Z'));
        $result = $this->runJob();

        self::assertSame(JobStatus::Failed, $result->status);
    }

    public function testAVehicleWithNoRecordIsSkippedAndTheRunGoesOn(): void
    {
        $this->start();
        $golf = $this->golf();
        $polo = $this->golf('CD34 EFG', model: 'Polo');
        $this->fetch($golf);
        $this->fetch($polo);
        $this->answer = static fn (string $url): ?MockResponse => str_contains($url, 'AB12CDE')
            ? new MockResponse((string) file_get_contents(self::FIXTURES . 'not-found.json'), ['http_code' => 404])
            : null;

        $this->clock->set(new DateTimeImmutable('2027-03-01T09:00:00Z'));
        $result = $this->runJob();

        self::assertSame(JobStatus::Ok, $result->status);
        self::assertSame(1, $result->count('skipped'));
        self::assertSame(1, $result->count('refreshed'));
    }

    public function testTheKeepAliveCallsAfterEightyIdleDaysOrWhenNoneEverWorked(): void
    {
        $this->start();
        $config = $this->service($this->app, MotHistoryConfig::class);

        // Never succeeded (#342): it calls, sending no vehicle.
        $result = $this->runJob();
        self::assertSame(1, $result->count('pinged'));
        self::assertSame([], array_values(array_filter(
            $this->vehicleRequests(),
            static fn (string $url): bool => !str_contains($url, 'bulk-download'),
        )));
        self::assertSame('2026-10-08', $config->status()->lastSuccessAt?->format('Y-m-d'));

        // 80 days on: not yet.
        $this->clock->set(new DateTimeImmutable('2026-12-27T09:00:00Z'));
        self::assertSame(0, $this->runJob()->count('pinged'));
        // 81 days on: again.
        $this->clock->set(new DateTimeImmutable('2026-12-28T09:01:00Z'));
        self::assertSame(1, $this->runJob()->count('pinged'));

        // A failing keep-alive fails the run, and shows on Settings.
        $config->saveStatus(new MotHistoryStatus());
        $this->answer = static fn (string $url): MockResponse => new MockResponse('{}', ['http_code' => 401]);
        $failed = $this->runJob();
        self::assertSame(JobStatus::Failed, $failed->status);
        self::assertNotNull($config->status()->error);
    }

    private function runJob(): JobResult
    {
        return $this->service($this->app, MotHistoryJob::class)
            ->run(new JobContext(new NullLogger(), static fn (): bool => false));
    }
}
