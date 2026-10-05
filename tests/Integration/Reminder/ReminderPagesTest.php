<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Repository\UserRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Tests\Support\ReminderTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The reminder list, its actions, manual reminders and lead-time settings,
 * end to end (spec.md §7.6).
 */
final class ReminderPagesTest extends ReminderTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    public function testSchedulesAndDocumentsShowUpWithTheirStatus(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $this->document($app, $golf, '2026-09-20', ComplianceType::Inspection, null, 'MOT');
        $this->schedule($app, $golf, 'Annual service', '2025-12-01');

        $page = $browser->get('/reminders');
        self::assertSame(200, $page->getStatusCode());
        $html = self::body($page);
        self::assertStringContainsString('2 reminders need attention', $html);
        self::assertStringContainsString('Overdue (1)', $html);
        self::assertStringContainsString('Expired 7 days ago', $html);
        self::assertStringContainsString('Due soon (1)', $html);
        self::assertStringContainsString('Expires in 12 days', $html);
        self::assertStringContainsString('Upcoming (1)', $html);
        self::assertStringContainsString('Due in 65 days', $html);
        self::assertStringContainsString('Due 1 Dec 2026', $html);
        self::assertStringContainsString('>Insurance<', $html, 'an untitled document is named by its type');
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/documents"', $html, 'links to its source');

        $statuses = array_map(
            static fn (Reminder $r): string => $r->source->value . ':' . $r->status->value,
            $this->reminders($app),
        );
        sort($statuses);
        self::assertSame(['compliance:due', 'compliance:overdue', 'schedule:upcoming'], $statuses);

        $home = self::body($browser->get('/'));
        self::assertStringContainsString('2 reminders need attention', $home, 'the home page shows what needs attention');
        self::assertStringContainsString('Expired 7 days ago', $home);

        // Reading again changes nothing.
        $before = $this->reminders($app);
        $browser->get('/reminders');
        self::assertEquals($before, $this->reminders($app));
    }

    public function testDismissDoneAndReopen(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $browser->get('/reminders');
        $reminder = $this->onlyReminder($app);

        $dismissed = $browser->post('/reminders/' . $reminder->id . '/dismiss');
        self::assertSame(303, $dismissed->getStatusCode());
        self::assertSame('/reminders', $dismissed->getHeaderLine('Location'));
        $html = self::body($browser->follow($dismissed));
        self::assertStringContainsString('The reminder was dismissed.', $html);
        self::assertStringContainsString('1 dismissed or done', $html);
        self::assertStringContainsString('Nothing needs attention', $html);
        self::assertSame(ReminderStatus::Dismissed, $this->onlyReminder($app)->status);
        self::assertNotNull($this->onlyReminder($app)->closedAt);

        $browser->get('/reminders');
        self::assertSame(ReminderStatus::Dismissed, $this->onlyReminder($app)->status, 'a sync never reopens it');

        $browser->post('/reminders/' . $reminder->id . '/reopen');
        self::assertSame(ReminderStatus::Due, $this->onlyReminder($app)->status, 'reopened to what today calls for');
        self::assertNull($this->onlyReminder($app)->closedAt);

        $browser->post('/reminders/' . $reminder->id . '/done');
        self::assertSame(ReminderStatus::Done, $this->onlyReminder($app)->status);
        self::assertStringContainsString('Marked done', self::body($browser->get('/reminders')));

        self::assertSame(404, $browser->post('/reminders/' . $reminder->id . '/snooze')->getStatusCode());
        self::assertSame(404, $browser->post('/reminders/999999/done')->getStatusCode());
    }

    public function testRenewingOrEditingTheSourceStartsANewOccurrence(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $policy = $this->document($app, $golf, '2026-10-09');
        $browser->get('/reminders');
        $browser->post('/reminders/' . $this->onlyReminder($app)->id . '/done');

        // Editing the expiry is a new due point: open again, nothing sent yet.
        $this->service($app, ComplianceService::class)->update($golf, $policy, new ComplianceDocumentData(
            type: ComplianceType::Insurance,
            expiryOn: self::date('2026-10-20'),
        ), new DateTimeZone('Europe/London'));
        $browser->get('/reminders');
        $moved = $this->onlyReminder($app);
        self::assertSame(ReminderStatus::Due, $moved->status);
        self::assertSame('2026-10-20', $moved->dueOn?->format('Y-m-d'));
        self::assertNull($moved->closedAt);

        // Renewing replaces the policy: its reminder goes, the renewal's arrives.
        $renewal = $this->document($app, $golf, '2027-10-19', ComplianceType::Insurance, '2026-10-21');
        $browser->get('/reminders');
        $current = $this->onlyReminder($app);
        self::assertSame($renewal->id, $current->sourceId);
        self::assertSame(ReminderStatus::Upcoming, $current->status);
    }

    public function testLoggingAScheduleClearsItsReminder(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $schedule = $this->schedule($app, $golf, 'Annual service', '2025-10-01');
        $browser->get('/reminders');
        self::assertSame(ReminderStatus::Due, $this->onlyReminder($app)->status, 'due 1 Oct 2026');

        $browser->post('/vehicles/' . $golf->id . '/maintenance/new', [
            'title' => 'Annual service',
            'category' => 'service',
            'performed_on' => '2026-09-27',
            'odometer' => '',
            'cost' => '180',
            'vendor' => '',
            'description' => '',
            'schedule' => (string) $schedule->id,
        ]);

        $browser->get('/reminders');
        $next = $this->onlyReminder($app);
        self::assertSame(ReminderStatus::Upcoming, $next->status);
        self::assertSame('2027-09-27', $next->dueOn?->format('Y-m-d'));
    }

    public function testLeadTimesComeFromSettingsAndTheVehiclePagesAgree(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $this->schedule($app, $golf, 'Annual service', '2025-12-01');

        $form = self::body($browser->get('/settings/reminders'));
        self::assertStringContainsString('name="schedule_days" type="number" value="30"', $form);
        self::assertStringContainsString('name="schedule_distance" type="number" value="621"', $form, '1,000 km in miles');

        $saved = $browser->post('/settings/reminders', [
            'schedule_days' => '90',
            'schedule_distance' => '600',
            'document_days' => '7',
            'manual_days' => '3',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertStringContainsString('Your reminder settings were saved.', self::body($browser->follow($saved)));

        $list = self::body($browser->get('/reminders'));
        self::assertStringContainsString('Due soon (1)', $list, 'the service, 65 days away, is within 90 days');
        self::assertStringContainsString('Upcoming (1)', $list, 'the policy, 12 days away, is not within 7');

        $documents = self::body($browser->get('/vehicles/' . $golf->id . '/documents'));
        self::assertStringNotContainsString('Expires in 12 days', $documents);
        self::assertStringContainsString('Documents expiring within 7 days are marked', $documents);
        $maintenance = self::body($browser->get('/vehicles/' . $golf->id . '/maintenance'));
        self::assertStringContainsString('Due in 65 days', $maintenance, 'due soon with a 90-day lead time');

        $invalid = $browser->post('/settings/reminders', [
            'schedule_days' => '400',
            'schedule_distance' => '600',
            'document_days' => '7',
            'manual_days' => '3',
        ]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Must be at most 365.', self::body($invalid));
    }

    public function testManualReminders(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $bike = $this->vehicle($app, 'Bike');

        $form = self::body($browser->get('/reminders/new?vehicle=' . $bike->id));
        self::assertStringContainsString('<option value="' . $bike->id . '" selected>', $form);
        self::assertStringContainsString('name="lead_time_days" type="number" value="7"', $form);

        $invalid = $browser->post('/reminders/new', [
            'vehicle_id' => (string) $golf->id,
            'title' => '',
            'due_on' => '2026-02-30',
            'lead_time_days' => '7',
        ]);
        self::assertSame(422, $invalid->getStatusCode());

        $created = $browser->post('/reminders/new', [
            'vehicle_id' => (string) $golf->id,
            'title' => 'Pay road tax',
            'due_on' => '2026-10-01',
            'lead_time_days' => '7',
            'notes' => 'Online, 12 months',
        ]);
        self::assertSame('/reminders', $created->getHeaderLine('Location'));
        $html = self::body($browser->follow($created));
        self::assertStringContainsString('The reminder “Pay road tax” was added.', $html);
        self::assertStringContainsString('Due in 4 days', $html);
        self::assertStringContainsString('Online, 12 months', $html);

        $reminder = $this->onlyReminder($app);
        self::assertSame(ReminderSource::Manual, $reminder->source);
        self::assertSame(ReminderStatus::Due, $reminder->status);

        $edit = '/reminders/' . $reminder->id . '/edit';
        self::assertStringContainsString('value="Pay road tax"', self::body($browser->get($edit)));
        $browser->post($edit, [
            'vehicle_id' => (string) $bike->id,
            'title' => 'Pay road tax',
            'due_on' => '2026-12-01',
            'lead_time_days' => '7',
            'notes' => '',
        ]);
        $edited = $this->onlyReminder($app);
        self::assertSame($reminder->id, $edited->id);
        self::assertSame($bike->id, $edited->vehicleId);
        self::assertSame(ReminderStatus::Upcoming, $edited->status);
        self::assertNull($edited->notes);

        $confirm = self::body($browser->get('/reminders/' . $reminder->id . '/delete'));
        self::assertStringContainsString('Delete the reminder “Pay road tax”?', $confirm);
        $deleted = $browser->post('/reminders/' . $reminder->id . '/delete');
        self::assertSame('/reminders', $deleted->getHeaderLine('Location'));
        self::assertSame([], $this->reminders($app));
    }

    /**
     * A manual reminder due at an odometer (spec.md §7.6, Phase 26.4): kept
     * as a distance, judged whichever comes first, overdue once a reading
     * passes it, and shown "Due at …" while it has no date.
     */
    public function testAManualReminderCanBeDueAtAnOdometer(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $readings = '/vehicles/' . $golf->id . '/odometer/new';
        $browser->post($readings, ['recorded_at' => '2026-09-01T09:00', 'reading' => '10000']);
        $browser->post($readings, ['recorded_at' => '2026-09-20T09:00', 'reading' => '10300']);

        $form = self::body($browser->get('/reminders/new'));
        self::assertStringContainsString('name="due_odometer"', $form);

        $neither = $browser->post('/reminders/new', [
            'vehicle_id' => (string) $golf->id,
            'title' => 'Front pads',
            'lead_time_days' => '7',
        ]);
        self::assertSame(422, $neither->getStatusCode());
        self::assertStringContainsString('Enter a date, an odometer reading, or both.', self::body($neither));

        $browser->post('/reminders/new', [
            'vehicle_id' => (string) $golf->id,
            'title' => 'Front pads',
            'due_odometer' => '12000',
            'lead_time_days' => '7',
        ]);
        $reminder = $this->onlyReminder($app);
        self::assertNull($reminder->dueOn);
        self::assertNotNull($reminder->dueKm);
        self::assertSame(ReminderStatus::Upcoming, $reminder->status);
        self::assertStringContainsString('Due at 12,000', self::body($browser->get('/reminders')));
        self::assertStringContainsString(
            'name="due_odometer" type="number" value="12000"',
            self::body($browser->get('/reminders/' . $reminder->id . '/edit')),
        );

        $browser->post($readings, ['recorded_at' => '2026-09-26T09:00', 'reading' => '12010']);
        $browser->get('/reminders');
        self::assertSame(ReminderStatus::Overdue, $this->onlyReminder($app)->status, 'past the odometer');
    }

    /**
     * The manual reminder form opens as a desktop modal (spec.md §5, Phase
     * 21.1): the form alone with the header, errors inside the dialog, a
     * save answered with the location.
     */
    public function testTheManualReminderFormRendersInTheModal(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $bike = $this->vehicle($app, 'Bike');

        $modal = ['X-Logbook-Modal' => '1'];
        self::assertStringContainsString('href="/reminders/new" data-modal', self::body($browser->get('/reminders')));

        $form = self::body($browser->get('/reminders/new', $modal));
        self::assertStringContainsString('data-modal-fragment', $form);
        self::assertStringNotContainsString('class="back-link"', $form);

        $fields = ['vehicle_id' => (string) $golf->id, 'title' => '', 'due_on' => '', 'lead_time_days' => '7'];
        $invalid = $browser->post('/reminders/new', $fields, headers: $modal);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('data-modal-fragment', self::body($invalid));

        $fields = ['title' => 'Pay road tax', 'due_on' => '2026-10-01'] + $fields;
        $created = $browser->post('/reminders/new', $fields, headers: $modal);
        self::assertSame(204, $created->getStatusCode());
        self::assertSame('/reminders', $created->getHeaderLine('X-Logbook-Location'));
    }

    public function testGeneratedRemindersAreNotEditedDirectly(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');
        $browser->get('/reminders');
        $id = $this->onlyReminder($app)->id;

        self::assertSame(404, $browser->get('/reminders/' . $id . '/edit')->getStatusCode());
        self::assertSame(404, $browser->post('/reminders/' . $id . '/delete')->getStatusCode());
        self::assertCount(1, $this->reminders($app));
    }

    public function testArchivedVehiclesHaveNoReminders(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, '2026-10-09');
        $browser->get('/reminders');
        self::assertCount(1, $this->reminders($app));

        $vehicles = $this->service($app, VehicleService::class);
        $vehicles->archive($this->owner($app), $golf);
        self::assertStringContainsString('Nothing coming up', self::body($browser->get('/reminders')));
        self::assertSame([], $this->reminders($app), 'its generated reminders are removed');

        $vehicles->restore($this->owner($app), $golf);
        $browser->get('/reminders');
        self::assertCount(1, $this->reminders($app), 'and come back with it');
    }

    /**
     * "Due today" is the owner's today: just after midnight in Auckland it
     * is already tomorrow there, while it is still yesterday in London.
     */
    public function testTodayIsInTheOwnersTimeZone(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-10-08T12:30:00Z');
        $browser = $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');

        self::assertStringContainsString('Expires tomorrow', self::body($browser->get('/reminders')), 'London: 8 Oct, 13:30');

        $this->setTimezone($app, 'Pacific/Auckland');
        self::assertStringContainsString('Expires today', self::body($browser->get('/reminders')), 'Auckland: 9 Oct, 01:30');
        self::assertSame(ReminderStatus::Due, $this->onlyReminder($app)->status, 'still due on its last day');
    }

    public function testWorksBehindASubpath(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');

        // Hard refresh with the prefix stripped by the proxy.
        $html = self::body($browser->get('/reminders'));
        $id = $this->onlyReminder($app)->id;
        self::assertStringContainsString('action="/logbook/reminders/' . $id . '/dismiss"', $html);
        self::assertStringContainsString('href="/logbook/settings/reminders"', $html);

        $dismissed = $browser->post('/logbook/reminders/' . $id . '/dismiss');
        self::assertSame('/logbook/reminders', $dismissed->getHeaderLine('Location'));
        self::assertSame(ReminderStatus::Dismissed, $this->onlyReminder($app)->status);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function setTimezone(App $app, string $zone): void
    {
        $owner = $this->owner($app);
        $p = $owner->preferences;
        $this->service($app, UserRepository::class)->updateProfile(
            $owner->id,
            $owner->displayName,
            new DisplayPreferences($p->locale, $zone, $p->distanceUnit, $p->volumeUnit, $p->consumptionUnit, $p->currency),
            new DateTimeImmutable(self::NOW),
        );
    }
}
