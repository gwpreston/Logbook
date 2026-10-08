<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Issue;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Issue\IssueUpdateData;
use Logbook\Domain\Issue\IssueUpdateReason;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Issue\IssueNotFound;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Issues through the service against a real database (spec.md §7.37):
 * statuses and the timeline, fixing from either side, unlinking, readings.
 */
final class IssueServiceTest extends AppTestCase
{
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private Vehicle $golf;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->golf = $this->vehicle($this->app);
    }

    private function issues(): IssueService
    {
        return $this->service($this->app, IssueService::class);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone('Europe/London');
    }

    private function log(
        string $title = 'Knock from front left under braking',
        IssueStatus $status = IssueStatus::Open,
        ?string $odometerKm = null,
        string $date = '2026-08-12',
    ): Issue {
        return $this->issues()->create($this->golf, new IssueData(
            noticedOn: self::day($date),
            title: $title,
            status: $status,
            odometerKm: $odometerKm,
            category: MaintenanceCategory::Brakes,
        ), self::zone());
    }

    /**
     * @param list<int>|null $fixes
     */
    private function record(string $date = '2026-09-01', ?array $fixes = null): MaintenanceEntry
    {
        return $this->service($this->app, MaintenanceService::class)->create(
            $this->golf,
            new MaintenanceEntryData(self::day($date), MaintenanceCategory::Brakes, 'Front pads and discs', '240.00'),
            self::zone(),
            fixes: $fixes,
        );
    }

    /**
     * @return list<string> each update's reason, oldest first
     */
    private function reasons(Issue $issue): array
    {
        return array_map(
            static fn ($u): string => $u->reason !== null ? $u->reason->value : 'note',
            $this->issues()->updatesOf($issue),
        );
    }

    public function testAnIssueIsLoggedOpenWithItsReading(): void
    {
        $issue = $this->log(odometerKm: '48120.000');

        self::assertSame(IssueStatus::Open, $issue->status());
        self::assertSame('48120.000', $issue->data->odometerKm);
        $reading = $this->service($this->app, OdometerReadingRepository::class)
            ->findByEntry($this->golf->id, OdometerSource::Issue, $issue->id);
        self::assertNotNull($reading);
        self::assertSame('48120.000', $reading->readingKm);
        self::assertSame('2026-08-12T11:00:00+00:00', $reading->recordedAt->format(DATE_ATOM), 'local noon on its date');
        self::assertSame($this->owner->id, $issue->createdBy);
    }

    public function testEditingMovesAndClearingRemovesTheReading(): void
    {
        $issue = $this->log(odometerKm: '48120.000');
        $repo = $this->service($this->app, OdometerReadingRepository::class);

        $moved = new IssueData(self::day('2026-08-13'), 'Knock', odometerKm: '48200.000');
        $this->issues()->update($this->golf, $issue, $moved, self::zone());
        self::assertSame('48200.000', $repo->findByEntry($this->golf->id, OdometerSource::Issue, $issue->id)?->readingKm);

        $this->issues()->update($this->golf, $issue, new IssueData(self::day('2026-08-13'), 'Knock'), self::zone());
        self::assertNull($repo->findByEntry($this->golf->id, OdometerSource::Issue, $issue->id));
    }

    public function testALookAgainPointIsOnlyKeptWhileWatching(): void
    {
        $issue = $this->issues()->create($this->golf, new IssueData(
            noticedOn: self::day('2026-08-12'),
            title: 'Brake pipes corroded',
            status: IssueStatus::Open,
            lookAgainOn: self::day('2026-12-01'),
        ), self::zone());
        self::assertNull($issue->data->lookAgainOn);

        $watched = $this->issues()->watch($this->golf, $issue, self::day('2026-12-01'), '60000.000', self::zone());
        self::assertSame(IssueStatus::Watching, $watched->status());
        self::assertSame('2026-12-01', $watched->data->lookAgainOn?->format('Y-m-d'));
        self::assertSame('60000.000', $watched->data->lookAgainKm);

        $open = $this->issues()->stopWatching($this->golf, $watched, self::zone());
        self::assertSame(IssueStatus::Open, $open->status());
        self::assertNull($open->data->lookAgainOn);
        self::assertNull($open->data->lookAgainKm);
        self::assertSame(['watch', 'stop_watching'], $this->reasons($open));
    }

    public function testOneRecordFixesSeveralIssuesFromTheRecordSide(): void
    {
        $knock = $this->log();
        $pipes = $this->log('Brake pipes corroded');
        $pipes = $this->issues()->watch($this->golf, $pipes, self::day('2026-12-01'), null, self::zone());

        $record = $this->record('2026-09-01', [$knock->id, $pipes->id]);

        foreach ([$knock, $pipes] as $issue) {
            $fixed = $this->issues()->get($this->golf, $issue->id);
            self::assertSame(IssueStatus::Fixed, $fixed->status());
            self::assertSame('2026-09-01', $fixed->fixedOn?->format('Y-m-d'));
            self::assertNull($fixed->data->lookAgainOn, 'a fixed issue keeps no look-again point');
            self::assertSame([$record->id], array_map(static fn ($r) => $r->id, $this->issues()->fixesOf($this->golf, $fixed)));
        }
        self::assertSame([$knock->id, $pipes->id], $this->issues()->fixedBy($record->id));
    }

    public function testUntickingOnTheRecordReturnsTheIssueToItsEarlierStatus(): void
    {
        $pipes = $this->issues()->watch($this->golf, $this->log('Brake pipes corroded'), null, null, self::zone());
        $record = $this->record('2026-09-01', [$pipes->id]);

        $this->service($this->app, MaintenanceService::class)->update(
            $this->golf,
            $record,
            $record->data,
            self::zone(),
            fixes: [],
        );

        $back = $this->issues()->get($this->golf, $pipes->id);
        self::assertSame(IssueStatus::Watching, $back->status());
        self::assertNull($back->fixedOn);
        // By date: the fix on the record's, the rest today.
        self::assertSame(['fixed', 'watch', 'record_unlinked'], $this->reasons($back));
    }

    public function testDeletingTheRecordReopensTheIssue(): void
    {
        $knock = $this->log();
        $record = $this->record('2026-09-01', [$knock->id]);

        $this->service($this->app, MaintenanceService::class)->delete($this->golf, $record, self::zone());

        $back = $this->issues()->get($this->golf, $knock->id);
        self::assertSame(IssueStatus::Open, $back->status());
        self::assertSame(['fixed', 'record_deleted'], $this->reasons($back));
    }

    public function testASecondAttemptKeepsTheIssueFixedOnTheLatestRecord(): void
    {
        $knock = $this->log();
        $first = $this->record('2026-08-20', [$knock->id]);
        $second = $this->record('2026-09-10');

        $fixed = $this->issues()->fixWith($this->golf, $this->issues()->get($this->golf, $knock->id), [$second->id]);
        self::assertSame('2026-09-10', $fixed->fixedOn?->format('Y-m-d'));

        $this->service($this->app, MaintenanceService::class)->delete($this->golf, $second, self::zone());
        $still = $this->issues()->get($this->golf, $knock->id);
        self::assertSame(IssueStatus::Fixed, $still->status(), 'one fixing record is left');
        self::assertSame('2026-08-20', $still->fixedOn?->format('Y-m-d'));
        self::assertSame([$knock->id], $this->issues()->fixedBy($first->id));
    }

    public function testMovingTheRecordMovesTheFixedDate(): void
    {
        $knock = $this->log();
        $record = $this->record('2026-09-01', [$knock->id]);

        $this->service($this->app, MaintenanceService::class)->update(
            $this->golf,
            $record,
            new MaintenanceEntryData(self::day('2026-09-03'), MaintenanceCategory::Brakes, 'Front pads', '240.00'),
            self::zone(),
        );

        self::assertSame('2026-09-03', $this->issues()->get($this->golf, $knock->id)->fixedOn?->format('Y-m-d'));
    }

    public function testFixedWithoutARecordStaysFixedWhenALaterLinkIsRemoved(): void
    {
        $knock = $this->log();
        $fixed = $this->issues()->fixWithoutRecord($this->golf, $knock, self::day('2026-09-02'), 'Went away on its own');
        self::assertSame(IssueStatus::Fixed, $fixed->status());
        self::assertNull($fixed->statusBeforeFix);

        $record = $this->record('2026-09-05');
        $this->issues()->fixWith($this->golf, $fixed, [$record->id]);
        $this->service($this->app, MaintenanceService::class)->delete($this->golf, $record, self::zone());

        $still = $this->issues()->get($this->golf, $knock->id);
        self::assertSame(IssueStatus::Fixed, $still->status());
        self::assertSame('2026-09-02', $still->fixedOn?->format('Y-m-d'));
        $updates = $this->issues()->updatesOf($still);
        self::assertSame('Went away on its own', $updates[0]->note);
        self::assertSame(IssueUpdateReason::FixedWithoutRecord, $updates[0]->reason);
    }

    public function testItsBackKeepsTheLinks(): void
    {
        $knock = $this->log();
        $record = $this->record('2026-09-01', [$knock->id]);

        $back = $this->issues()->reopen($this->golf, $this->issues()->get($this->golf, $knock->id), self::zone());

        self::assertSame(IssueStatus::Open, $back->status());
        self::assertNull($back->fixedOn);
        self::assertSame([$knock->id], $this->issues()->fixedBy($record->id), 'the earlier fix is history');
        self::assertSame(['fixed', 'back'], $this->reasons($back));
    }

    public function testLinkableRecordsAreThoseSinceTheIssueWasNoticed(): void
    {
        $knock = $this->log(date: '2026-08-12');
        $this->record('2026-08-01');
        $after = $this->record('2026-08-15');

        $linkable = $this->issues()->linkableRecords($this->golf, $knock);
        self::assertSame([$after->id], array_map(static fn ($r) => $r->id, $linkable));
    }

    public function testUpdatesWriteReadingsAndCanChangeTheStatus(): void
    {
        $knock = $this->log();
        $repo = $this->service($this->app, OdometerReadingRepository::class);

        $update = $this->issues()->addUpdate($this->golf, $knock, new IssueUpdateData(
            notedOn: self::day('2026-09-01'),
            note: 'Still knocking, worse when cold',
            odometerKm: '48500.000',
            status: IssueStatus::Watching,
            lookAgainOn: self::day('2026-12-01'),
        ), self::zone());

        self::assertTrue($update->isAutomatic(), 'a status change made with a note is a status line');
        self::assertSame('Still knocking, worse when cold', $update->note);
        self::assertSame(IssueStatus::Watching, $update->statusTo);
        $watching = $this->issues()->get($this->golf, $knock->id);
        self::assertSame(IssueStatus::Watching, $watching->status());
        self::assertSame('2026-12-01', $watching->data->lookAgainOn?->format('Y-m-d'));
        self::assertSame('48500.000', $repo->findByEntry($this->golf->id, OdometerSource::IssueUpdate, $update->id)?->readingKm);
    }

    public function testANoteCanBeEditedAndDeletedWithItsReading(): void
    {
        $knock = $this->log();
        $repo = $this->service($this->app, OdometerReadingRepository::class);
        $note = $this->issues()->addUpdate($this->golf, $knock, new IssueUpdateData(
            self::day('2026-09-01'),
            'Still knocking',
            '48500.000',
        ), self::zone());
        self::assertFalse($note->isAutomatic());

        $worse = new IssueUpdateData(self::day('2026-09-02'), 'Worse', '48600.000');
        $this->issues()->editUpdate($this->golf, $knock, $note, $worse, self::zone());
        $edited = $this->issues()->getUpdate($knock, $note->id);
        self::assertSame('Worse', $edited->note);
        self::assertSame('48600.000', $repo->findByEntry($this->golf->id, OdometerSource::IssueUpdate, $note->id)?->readingKm);

        $this->issues()->deleteUpdate($this->golf, $knock, $edited);
        self::assertNull($repo->findByEntry($this->golf->id, OdometerSource::IssueUpdate, $note->id));
        $this->expectException(IssueNotFound::class);
        $this->issues()->getUpdate($knock, $note->id);
    }

    public function testAnAutomaticLineIsNeverEditedOrDeleted(): void
    {
        $watched = $this->issues()->watch($this->golf, $this->log(), null, null, self::zone());
        $line = $this->issues()->updatesOf($watched)[0];
        self::assertTrue($line->isAutomatic());

        $changed = new IssueUpdateData(self::day('2026-09-02'), 'Changed');
        $this->issues()->editUpdate($this->golf, $watched, $line, $changed, self::zone());
        $this->issues()->deleteUpdate($this->golf, $watched, $line);

        $kept = $this->issues()->getUpdate($watched, $line->id);
        self::assertNull($kept->note);
    }

    public function testLookedAtClearsThePointAndKeepsWatching(): void
    {
        $watched = $this->issues()->watch($this->golf, $this->log(), self::day('2026-09-01'), null, self::zone());

        $this->issues()->lookedAt($this->golf, $watched, self::zone());

        $after = $this->issues()->get($this->golf, $watched->id);
        self::assertSame(IssueStatus::Watching, $after->status());
        self::assertFalse($after->data->hasLookAgain());
        self::assertSame(['watch', 'looked_at'], $this->reasons($after));
    }

    public function testDeletingAnIssueRemovesItsReadingsAndKeepsTheRecord(): void
    {
        $knock = $this->log(odometerKm: '48120.000');
        $data = new IssueUpdateData(self::day('2026-09-01'), 'x', '48500.000');
        $update = $this->issues()->addUpdate($this->golf, $knock, $data, self::zone());
        $record = $this->record('2026-09-02', [$knock->id]);

        $this->issues()->delete($this->golf, $this->issues()->get($this->golf, $knock->id));

        $repo = $this->service($this->app, OdometerReadingRepository::class);
        self::assertNull($repo->findByEntry($this->golf->id, OdometerSource::Issue, $knock->id));
        self::assertNull($repo->findByEntry($this->golf->id, OdometerSource::IssueUpdate, $update->id));
        self::assertNotNull($this->service($this->app, MaintenanceService::class)->find($this->golf, $record->id));
        self::assertSame([], $this->issues()->fixedBy($record->id));
    }

    public function testSafetyIssuesAreListedFirst(): void
    {
        $older = $this->issues()->create($this->golf, new IssueData(
            noticedOn: self::day('2026-07-01'),
            title: 'Steering pulls left',
            affectsSafety: true,
        ), self::zone());
        $newer = $this->log(date: '2026-08-12');

        $listed = $this->issues()->unresolved($this->golf);
        self::assertSame([$older->id, $newer->id], array_map(static fn (Issue $i) => $i->id, $listed));
    }
}
