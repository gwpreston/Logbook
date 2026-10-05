<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Reminder\ReminderPreferences;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * The first MOT reminder (spec.md §7.6 *First MOT*, Phase 21.2): one per
 * vehicle from its *First MOT due* date, on the document lead time, moved
 * with the date, removed when it is cleared, and done (and kept) once the
 * first certificate is logged, after which only the certificate's reminder
 * is open.
 */
final class FirstInspectionReminderTest extends ReminderTestCase
{
    private const string NOW = '2026-09-30T10:00:00Z';

    /** @var App<ContainerInterface> */
    private App $app;
    private MutableClock $clock;
    private TestBrowser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createRecordingApp();
        $this->clock = $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
    }

    public function testRaisedFromTheDateOnTheDocumentLeadTime(): void
    {
        $golf = $this->car('2026-10-20');
        $this->sync();

        $reminder = $this->firstMot($golf);
        self::assertNotNull($reminder);
        self::assertSame($golf->id, $reminder->sourceId, 'one per vehicle: its own id');
        self::assertSame('First MOT', $reminder->title, 'in the owner’s language');
        self::assertSame('inspection', $reminder->category);
        self::assertSame('2026-10-20', $reminder->dueOn?->format('Y-m-d'));
        self::assertSame(30, $reminder->leadTimeDays, 'the document lead time');
        self::assertSame(ReminderStatus::Due, $reminder->status, '20 days away, within 30');

        $this->service($this->app, ReminderSettingsStore::class)
            ->saveReminderPreferences($this->owner($this->app)->id, new ReminderPreferences(documentDays: 10));
        $this->sync();
        $reminder = $this->firstMot($golf);
        self::assertSame(ReminderStatus::Upcoming, $reminder?->status, 'outside a 10-day lead time');
        self::assertSame(10, $reminder->leadTimeDays);

        $html = self::body($this->browser->get('/reminders'));
        self::assertStringContainsString('First MOT', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '"', $html, 'it links to the vehicle');
    }

    public function testUpcomingThenDueThenOverdue(): void
    {
        $golf = $this->car('2026-12-15');
        $this->sync();
        self::assertSame(ReminderStatus::Upcoming, $this->firstMot($golf)?->status);

        $this->clock->set(new DateTimeImmutable('2026-11-20T10:00:00Z'));
        $this->sync();
        self::assertSame(ReminderStatus::Due, $this->firstMot($golf)?->status);

        $this->clock->set(new DateTimeImmutable('2026-12-16T10:00:00Z'));
        $this->sync();
        self::assertSame(ReminderStatus::Overdue, $this->firstMot($golf)?->status);
    }

    public function testChangingTheDateMovesItAndClearingItRemovesIt(): void
    {
        $golf = $this->car('2026-10-20');
        $this->sync();
        $before = $this->firstMot($golf);
        self::assertNotNull($before);
        $this->service($this->app, ReminderService::class)->dismiss($before);

        $golf = $this->setDate($golf, '2027-06-14');
        $this->sync();
        $moved = $this->firstMot($golf);
        self::assertNotNull($moved);
        self::assertSame($before->id, $moved->id, 'the same reminder');
        self::assertSame('2027-06-14', $moved->occurrence, 'a new occurrence');
        self::assertSame(ReminderStatus::Upcoming, $moved->status, 'open again for the new date');

        $this->setDate($golf, null);
        $this->sync();
        self::assertNull($this->firstMot($golf), 'cleared: gone');
    }

    public function testTheFirstCertificateMarksItDoneAndOnlyItsReminderStaysOpen(): void
    {
        $golf = $this->car('2026-10-20');
        $this->sync();
        self::assertSame(ReminderStatus::Due, $this->firstMot($golf)?->status);

        $this->document($this->app, $golf, '2027-10-17', ComplianceType::Inspection, '2026-10-18');
        $this->sync();
        $this->sync();

        $done = $this->firstMot($golf);
        self::assertSame(ReminderStatus::Done, $done?->status, 'done, and kept rather than deleted');
        $open = array_values(array_filter($this->reminders($this->app), static fn (Reminder $r): bool => $r->status->isOpen()));
        self::assertCount(1, $open, 'never two MOT reminders');
        self::assertSame(ReminderSource::Compliance, $open[0]->source, 'the certificate’s expiry takes over');
        self::assertSame('2027-10-17', $open[0]->dueOn?->format('Y-m-d'));
    }

    public function testNotRaisedWithACertificateWhenArchivedOrWithComplianceOff(): void
    {
        $tested = $this->car('2026-10-20', 'Polo');
        $this->document($this->app, $tested, '2027-10-17', ComplianceType::Inspection, '2026-10-18');
        $archived = $this->car('2026-10-20', 'Up');
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $archived);
        $this->sync();
        self::assertNull($this->firstMot($tested), 'a certificate already: nothing to remind');
        self::assertNull($this->firstMot($archived), 'archived vehicles raise nothing');

        $golf = $this->car('2026-10-20');
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save([Feature::Fuel, Feature::Maintenance, Feature::Reminders, Feature::Reports]);
        $this->sync();
        self::assertNull($this->firstMot($golf), 'compliance off');
        self::assertStringNotContainsString('First MOT', self::body($this->browser->get('/reminders')));
    }

    public function testSentLikeAnyOtherReminderAndInTheCalendarFeed(): void
    {
        $this->car('2026-10-20');
        self::assertSame(1, $this->service($this->app, ScheduledTasks::class)->run()->remindersSent);
        self::assertStringContainsString('First MOT', (string) $this->mail->sent[0]->getSubject());

        $this->browser->get('/reminders');
        $issued = $this->browser->post('/settings/reminders/calendar', ['feed' => 'issue']);
        $html = self::body($this->browser->follow($issued));
        self::assertSame(1, preg_match('~id="f-feed-https" type="text" readonly value="([^"]+)"~', $html, $m));
        $ics = self::body((new TestBrowser($this->app))->get((string) parse_url(html_entity_decode($m[1] ?? ''), PHP_URL_PATH)));
        self::assertStringContainsString('SUMMARY:First MOT', $ics);
        self::assertContains('DTSTART;VALUE=DATE:20261020', explode("\r\n", $ics));
    }

    public function testTheMonthlyDigestIncludesIt(): void
    {
        $this->clock->set(new DateTimeImmutable('2026-10-01T07:00:00Z'));
        $this->car('2026-10-20');
        $this->browser->post('/settings/reminders', [
            'schedule_days' => '30',
            'schedule_distance' => '621',
            'document_days' => '30',
            'manual_days' => '7',
            'channels' => ['email'],
            'digest' => '1',
        ]);
        $this->service($this->app, ScheduledTasks::class)->run();

        $digests = array_values(array_filter(
            $this->mail->sent,
            static fn (Email $e): bool => str_starts_with((string) $e->getSubject(), 'Due in'),
        ));
        self::assertCount(1, $digests);
        self::assertStringContainsString('First MOT — Volkswagen Golf', (string) $digests[0]->getTextBody());
    }

    private function car(?string $firstMot, string $model = 'Golf'): Vehicle
    {
        return $this->service($this->app, VehicleService::class)->create($this->owner($this->app), new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            $model,
            FuelType::Petrol,
            firstRegisteredOn: self::date('2023-10-20'),
            firstInspectionDueOn: $firstMot === null ? null : self::date($firstMot),
        ));
    }

    private function setDate(Vehicle $vehicle, ?string $on): Vehicle
    {
        return $this->service($this->app, VehicleService::class)->update(
            $this->owner($this->app),
            $vehicle,
            $vehicle->data->withFirstInspectionDueOn($on === null ? null : self::date($on)),
        );
    }

    private function sync(): void
    {
        $this->service($this->app, ReminderSync::class)->sync($this->owner($this->app));
    }

    private function firstMot(Vehicle $vehicle): ?Reminder
    {
        foreach ($this->reminders($this->app) as $reminder) {
            if ($reminder->source === ReminderSource::FirstInspection && $reminder->vehicleId === $vehicle->id) {
                return $reminder;
            }
        }

        return null;
    }
}
