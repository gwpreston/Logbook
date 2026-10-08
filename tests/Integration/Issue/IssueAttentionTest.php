<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Issue;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attention\AttentionList;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Issues in *Needs attention* and their look-again reminders (spec.md
 * §7.37, §7.24, #311, #315): open issues are *Now* items, watching ones
 * come back once their point is reached, safety first; *Done* on the
 * reminder is *Looked at it*. "Today" is 1 Oct 2026 in London.
 */
final class IssueAttentionTest extends ReminderTestCase
{
    private const string NOW = '2026-10-01T10:00:00Z';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $golf;
    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createRecordingApp();
        $this->clock = $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->golf = $this->vehicle($this->app);
    }

    private function issues(): IssueService
    {
        return $this->service($this->app, IssueService::class);
    }

    private static function on(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private function log(string $title, string $noticed = '2026-09-10', bool $safety = false): Issue
    {
        return $this->issues()->create($this->golf, new IssueData(
            noticedOn: self::on($noticed),
            title: $title,
            affectsSafety: $safety,
        ), new DateTimeZone('Europe/London'));
    }

    private function watch(Issue $issue, ?string $on, ?string $km = null): Issue
    {
        return $this->issues()->watch(
            $this->golf,
            $issue,
            $on === null ? null : self::on($on),
            $km,
            new DateTimeZone('Europe/London'),
        );
    }

    /**
     * @return list<AttentionKind>
     */
    private function kinds(): array
    {
        $report = $this->service($this->app, AttentionList::class)->forVehicles($this->owner($this->app), [$this->golf]);

        return array_map(static fn ($item) => $item->kind, $report->items);
    }

    /**
     * @return list<string> the overview card's items
     */
    private function items(): array
    {
        $document = Html::document(self::body($this->browser->get('/vehicles/' . $this->golf->id)));
        $items = [];
        foreach ($document->querySelectorAll('section.card--attention li.attention-item') as $item) {
            $items[] = $document->saveHtml($item);
        }

        return $items;
    }

    public function testAnOpenIssueIsANowItemWithItsActions(): void
    {
        $knock = $this->log('Knock from front left under braking', '2026-09-10');

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertStringContainsString('Knock from front left under braking', $items[0]);
        self::assertStringContainsString('Noticed 10 Sept 2026, 3 weeks ago', $items[0]);
        self::assertStringContainsString('>Now</span>', $items[0]);
        self::assertStringContainsString('/maintenance/new?fixes=' . $knock->id, $items[0], 'Log the repair');
        self::assertStringContainsString('/issues/' . $knock->id . '/watch', $items[0]);
        self::assertStringNotContainsString('Hide', $items[0]);
    }

    public function testAWatchingIssueComesBackOnlyOnceItsPointIsReached(): void
    {
        $this->watch($this->log('Brake pipes corroded'), '2026-12-01');
        self::assertSame([], $this->kinds(), 'not yet');

        $this->clock->set(new DateTimeImmutable('2026-12-01T10:00:00Z'));
        self::assertSame([AttentionKind::IssueLookAgain], $this->kinds(), 'on its date');
        $this->browser = $this->browserFor($this->app, 'owner'); // two months on: a fresh sign-in
        $items = $this->items();
        self::assertStringContainsString('Watching since 1 Oct 2026', $items[0]);
        self::assertStringContainsString('Reopen', $items[0]);
        self::assertStringContainsString('Watch again', $items[0]);
    }

    public function testALookAgainMileageIsReachedByTheLatestReading(): void
    {
        $this->service($this->app, OdometerService::class)
            ->create($this->golf, new OdometerReadingData('48000', new DateTimeImmutable('2026-09-20T10:00:00Z')));
        $this->watch($this->log('Advisory: tyre near limit'), null, '50000.000');
        self::assertSame([], $this->kinds());

        $this->service($this->app, OdometerService::class)
            ->create($this->golf, new OdometerReadingData('50010', new DateTimeImmutable('2026-09-30T10:00:00Z')));
        self::assertSame([AttentionKind::IssueLookAgain], $this->kinds());
    }

    public function testSafetyIssuesComeFirstEvenBeforeOverdueWork(): void
    {
        $this->document($this->app, $this->golf, '2026-09-28', ComplianceType::Inspection, '2025-09-29');
        $this->log('Rattle from the dash');
        $this->log('Steering pulls left', '2026-09-20', true);

        $kinds = $this->kinds();
        self::assertSame(AttentionKind::IssueOpen, $kinds[0]);
        $items = $this->items();
        self::assertStringContainsString('Steering pulls left', $items[0]);
        self::assertStringContainsString('Affects safety', $items[0], 'in words, not colour alone');
    }

    public function testTheLookAgainReminderFollowsThePointAndDoneIsLookedAtIt(): void
    {
        $pipes = $this->watch($this->log('Brake pipes corroded'), '2026-10-05');
        $reminders = $this->service($this->app, ReminderService::class);
        $reminders->overview($this->owner($this->app));

        $reminder = $this->onlyReminder($this->app);
        self::assertSame(ReminderSource::Issue, $reminder->source);
        self::assertSame($pipes->id, $reminder->sourceId);
        self::assertSame('Look again: Brake pipes corroded', $reminder->title);
        self::assertSame(ReminderStatus::Due, $reminder->status, 'within the manual lead time of 7 days');
        self::assertStringContainsString('/issues/' . $pipes->id, self::body($this->browser->get('/reminders')));

        // A new point is a new occurrence.
        $pipes = $this->watch($this->issues()->get($this->golf, $pipes->id), '2026-11-30');
        $reminders->overview($this->owner($this->app));
        self::assertSame('2026-11-30', $this->onlyReminder($this->app)->dueOn?->format('Y-m-d'));

        // Done: Looked at it. The point is cleared, the issue stays watched, the reminder goes.
        $reminders->markDone($this->onlyReminder($this->app));
        $after = $this->issues()->get($this->golf, $pipes->id);
        self::assertSame(IssueStatus::Watching, $after->status());
        self::assertFalse($after->data->hasLookAgain());
        $reminders->overview($this->owner($this->app));
        self::assertSame([], $this->reminders($this->app));
    }

    public function testFixingOrStoppingRemovesTheReminder(): void
    {
        $pipes = $this->watch($this->log('Brake pipes corroded'), '2026-10-05');
        $reminders = $this->service($this->app, ReminderService::class);
        $reminders->overview($this->owner($this->app));
        self::assertCount(1, $this->reminders($this->app));

        $this->issues()->fixWithoutRecord($this->golf, $pipes, self::on('2026-10-01'), null);
        $reminders->overview($this->owner($this->app));
        self::assertSame([], $this->reminders($this->app));
    }

    public function testADismissedLookAgainLeavesNeedsAttention(): void
    {
        $this->watch($this->log('Brake pipes corroded'), '2026-09-30');
        $reminders = $this->service($this->app, ReminderService::class);
        $reminders->overview($this->owner($this->app));
        self::assertSame([AttentionKind::IssueLookAgain], $this->kinds());

        $reminders->dismiss($this->onlyReminder($this->app));
        self::assertSame([], $this->kinds());
    }

    public function testArchivedVehiclesAndTheModuleOffRaiseNothing(): void
    {
        $this->log('Knock');
        $this->watch($this->log('Pipes'), '2026-10-05');
        self::assertCount(1, $this->kinds());

        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Issues)));
        self::assertSame([], $this->kinds());
        $this->service($this->app, ReminderService::class)->overview($this->owner($this->app));
        self::assertStringNotContainsString('Look again', self::body($this->browser->get('/reminders')));

        $toggles->save(Feature::cases());
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $this->golf);
        $this->golf = $this->service($this->app, VehicleService::class)->get($this->owner($this->app), $this->golf->id);
        self::assertSame([], $this->kinds());
    }
}
