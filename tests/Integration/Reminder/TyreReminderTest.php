<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The tyre reminder (spec.md §7.6): one per vehicle, raised from the wear
 * estimate and age, shown and sent like every other reminder, and quiet
 * across fill-ups: only a new tyre change opens it again.
 *
 * The Golf's front tyres went on at 8.0 mm at 10,000 km and measured 3.6 and
 * 3.5 mm at 20,000 km; the car has done 20,500 km. The front right, at about
 * 3.3 mm now, reaches 3 mm in about 610 km (380 mi): due within the 1,000 km
 * lead distance.
 */
final class TyreReminderTest extends ReminderTestCase
{
    private const string NOW = '2026-09-29T10:00:00Z';
    private const string TITLE = 'Tyres: front right due in about 380 mi';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $golf;
    private TyreChange $fitted;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createRecordingApp();
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->golf = $this->vehicle($this->app);
        $changes = $this->service($this->app, TyreChangeService::class);
        $zone = new DateTimeZone('Europe/London');
        $this->fitted = $changes->existing($this->golf, new TyreChangeData(self::date('2026-01-01'), '10000.000'), [
            new NewTyre(TyrePosition::FrontLeft, new TyreData('Michelin', 'Primacy 4'), '8.000'),
            new NewTyre(TyrePosition::FrontRight, new TyreData('Michelin', 'Primacy 4'), '8.000'),
        ], $zone, 'en_GB');
        $this->check('2026-09-01', '20000.000', '3.600', '3.500');
        $this->reading('20500', '2026-09-28T09:00:00Z');
    }

    private function check(string $date, string $km, string $left, string $right): TyreChange
    {
        [$fl, $fr] = $this->fitted->tyreIds();

        return $this->service($this->app, TyreChangeService::class)->check(
            $this->golf,
            new TyreChangeData(self::date($date), $km),
            [$fl => $left, $fr => $right],
            new DateTimeZone('Europe/London'),
            'en_GB',
        );
    }

    private function reading(string $km, string $utc): void
    {
        $this->service($this->app, OdometerService::class)
            ->create($this->golf, new OdometerReadingData($km, new DateTimeImmutable($utc)));
    }

    private function sync(): void
    {
        $this->service($this->app, ReminderSync::class)->sync($this->owner($this->app));
    }

    private function tyreReminder(): ?Reminder
    {
        foreach ($this->reminders($this->app) as $reminder) {
            if ($reminder->source === ReminderSource::Tyre) {
                return $reminder;
            }
        }

        return null;
    }

    private function sent(): int
    {
        return $this->service($this->app, ScheduledTasks::class)->run()->remindersSent;
    }

    public function testOneReminderPerVehicleShownWhereverRemindersAre(): void
    {
        $html = self::body($this->browser->get('/reminders'));
        $reminder = $this->tyreReminder();

        self::assertNotNull($reminder);
        self::assertCount(1, $this->reminders($this->app), 'one for the vehicle, not one per tyre');
        self::assertSame($this->golf->id, $reminder->sourceId, 'the source id is the vehicle');
        self::assertSame(self::TITLE, $reminder->title);
        self::assertSame(ReminderStatus::Due, $reminder->status);
        self::assertNotNull($reminder->dueOn);
        self::assertNotNull($reminder->dueKm);
        self::assertStringContainsString(self::TITLE, $html);
        self::assertStringContainsString('href="/vehicles/' . $this->golf->id . '/tyres"', $html, 'it links to the Tyres tab');

        $dashboard = self::body($this->browser->get('/'));
        self::assertStringContainsString(self::TITLE, $dashboard, 'Next due and Upcoming reminders');
    }

    public function testTheCalendarFeedCarriesItAsAnEvent(): void
    {
        $this->browser->get('/reminders');
        $issued = $this->browser->post('/settings/reminders/calendar', ['feed' => 'issue']);
        $html = self::body($this->browser->follow($issued));
        self::assertSame(1, preg_match('~id="f-feed-https" type="text" readonly value="([^"]+)"~', $html, $m));
        $url = html_entity_decode($m[1] ?? '');
        $ics = self::body((new TestBrowser($this->app))->get((string) parse_url($url, PHP_URL_PATH)));
        $lines = explode("\r\n", $ics);

        self::assertContains('BEGIN:VEVENT', $lines);
        self::assertContains('END:VEVENT', $lines);
        self::assertStringContainsString('SUMMARY:' . self::TITLE, $ics);
        $reminder = $this->tyreReminder();
        self::assertNotNull($reminder?->dueOn);
        self::assertContains('DTSTART;VALUE=DATE:' . $reminder->dueOn->format('Ymd'), $lines);
    }

    public function testItIsSentOnceAndFillUpsNeverSendItAgain(): void
    {
        self::assertSame(1, $this->sent());
        self::assertSame(0, $this->sent(), 'the scheduled task again: nothing twice');
        self::assertCount(1, $this->mail->sent);
        self::assertStringContainsString(self::TITLE, (string) $this->mail->sent[0]->getSubject());
        $before = $this->tyreReminder();
        self::assertNotNull($before);

        // A day of driving moves the projection, and the reminder with it, in place.
        $this->reading('20600', '2026-09-29T08:00:00Z');
        $this->sync();
        $after = $this->tyreReminder();
        self::assertNotNull($after);
        self::assertSame($before->id, $after->id);
        self::assertSame($before->occurrence, $after->occurrence, 'the occurrence is the latest tyre change');
        self::assertSame('Tyres: front right due in about 320 mi', $after->title, 'updated in place');
        self::assertSame(ReminderStatus::Due, $after->notifiedStatus, 'notification state kept');
        self::assertSame(0, $this->sent(), 'and nothing is sent again');
    }

    public function testANewCheckReopensADismissedReminder(): void
    {
        $this->sync();
        $reminder = $this->tyreReminder();
        self::assertNotNull($reminder);
        $this->service($this->app, ReminderService::class)->dismiss($reminder);
        $this->reading('20700', '2026-09-29T09:00:00Z');
        $this->sync();
        self::assertSame(ReminderStatus::Dismissed, $this->tyreReminder()?->status, 'a fill-up does not reopen it');

        $check = $this->check('2026-09-29', '20700.000', '3.200', '3.100');
        $this->sync();
        $reopened = $this->tyreReminder();
        self::assertNotNull($reopened);
        self::assertSame((string) $check->id, $reopened->occurrence);
        self::assertTrue($reopened->status->isOpen(), 'a check opens it again for the new estimate');
        self::assertNull($reopened->notifiedStatus, 'and it will be sent again');
    }

    public function testNothingJudgeableRaisesNoneAndSyncRemovesTheOld(): void
    {
        $this->sync();
        self::assertNotNull($this->tyreReminder());

        $changes = $this->service($this->app, TyreChangeService::class);
        foreach ($changes->get($this->golf, $this->fitted->id)->lines as $line) {
            self::assertSame('8.000', $line->treadMm);
        }
        $latest = null;
        foreach ($this->service($this->app, TyreService::class)->changes($this->golf) as $change) {
            $latest ??= $change;
        }
        self::assertNotNull($latest);
        $changes->delete($this->golf, $latest);
        $this->sync();

        self::assertNull($this->tyreReminder(), 'one measurement at 8 mm and no DOT date: nothing to judge');
    }

    public function testArchivedVehiclesRaiseNone(): void
    {
        $this->sync();
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $this->golf);
        $this->sync();

        self::assertNull($this->tyreReminder());
    }

    public function testTyresOffHidesAndStopsItAndOnRestoresIt(): void
    {
        $this->sync();
        $toggles = $this->service($this->app, FeatureToggles::class);
        $all = [Feature::Fuel, Feature::Maintenance, Feature::Compliance, Feature::Reminders, Feature::Reports];
        $toggles->save($all);

        self::assertStringNotContainsString(self::TITLE, self::body($this->browser->get('/reminders')));
        self::assertSame(0, $this->sent(), 'not sent while tyres are off');
        self::assertNotNull($this->tyreReminder(), 'kept for when the module returns');

        $toggles->save([...$all, Feature::Tyres]);
        self::assertStringContainsString(self::TITLE, self::body($this->browser->get('/reminders')));
        self::assertSame(1, $this->sent());
    }

    public function testRemindersOffStillShowsTheTabBadge(): void
    {
        $this->service($this->app, FeatureToggles::class)
            ->save([Feature::Fuel, Feature::Maintenance, Feature::Compliance, Feature::Reports, Feature::Tyres]);
        $html = self::body($this->browser->get('/vehicles/' . $this->golf->id . '/tyres'));

        self::assertMatchesRegularExpression('~data-testid="tyre-verdict"[^>]*>.*Tyres due soon~s', $html);
        self::assertStringContainsString('Replace soon', $html, 'and the front right card says so');
        self::assertStringContainsString('about 3.3 mm now', $html);
        self::assertStringContainsString('3.5 mm on 1 Sept 2026', $html, 'the latest measurement with its date');
    }
}
