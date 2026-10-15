<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Part\WarrantyStart;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Part\PartForm;
use Logbook\Service\Part\PartRemoval;
use Logbook\Service\Part\PartService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Reminder\ReminderSync;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Warranty reminders (spec.md §7.6 *Part warranty reminders*, §7.39; Phase
 * 50.2, #437, #446): one per ticked part, due at the warranty's end (the
 * date, or the mileage projected), on the document lead time; gone with the
 * part, the tick, the warranty or its end; *Checked it* closes it.
 */
final class PartWarrantyReminderTest extends ReminderTestCase
{
    private const string NOW = '2026-09-30T10:00:00Z';

    /** @var App<ContainerInterface> */
    private App $app;
    private MutableClock $clock;
    private TestBrowser $browser;
    private Vehicle $golf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createRecordingApp(self::CHANNELS + ['FEATURES_PARTS' => 'true']);
        $this->clock = $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->golf = $this->vehicle($this->app);
    }

    public function testRaisedFromTheWarrantysEndOnTheDocumentLeadTime(): void
    {
        $part = $this->part('Clutch kit', brand: 'Sachs', months: 24, bought: '2026-03-01');
        $this->sync();

        $reminder = $this->reminderFor($part);
        self::assertNotNull($reminder);
        self::assertSame('Warranty ends: Sachs Clutch kit', $reminder->title, 'in the owner’s language, brand first');
        self::assertSame('2028-03-01', $reminder->dueOn?->format('Y-m-d'), 'the warranty’s end');
        self::assertNull($reminder->dueKm);
        self::assertSame(30, $reminder->leadTimeDays, 'the document lead time');
        self::assertSame(ReminderStatus::Upcoming, $reminder->status);
        self::assertSame('2028-03-01|', $reminder->occurrence);

        $this->clock->set(new DateTimeImmutable('2028-02-10T10:00:00Z'));
        $this->sync();
        self::assertSame(ReminderStatus::Due, $this->reminderFor($part)?->status, '20 days before the end, inside 30');
    }

    public function testADistanceLimitCountsFromTheFirstFittingsMileageAndIsDueNearIt(): void
    {
        $part = $this->part('Turbo', km: '20000', fitOn: '2026-01-10', fitKm: '10000');
        $this->reading('29500', '2026-09-29T09:00:00Z');
        $this->sync();

        $reminder = $this->reminderFor($part);
        self::assertNotNull($reminder);
        self::assertSame('30000.000', $reminder->dueKm, 'fitted at 10,000 km plus 20,000');
        self::assertNull($reminder->dueOn);
        self::assertSame(ReminderStatus::Due, $reminder->status, 'within the schedule lead distance of the end mileage');
        self::assertSame('|30000.000', $reminder->occurrence);
    }

    public function testTheDateAndTheMileageBothSet(): void
    {
        $part = $this->part('Gearbox', months: 36, km: '60000', bought: '2026-08-01', fitOn: '2026-08-05', fitKm: '40000');
        $this->sync();

        $reminder = $this->reminderFor($part);
        self::assertSame('2029-08-01', $reminder?->dueOn?->format('Y-m-d'));
        self::assertSame('100000.000', $reminder->dueKm);
    }

    public function testOnlyATickedPartRemindsAndClearingTheTickRemovesIt(): void
    {
        $plain = $this->part('Wiper blades', months: 12, bought: '2026-08-01', remind: false);
        $ticked = $this->part('Alternator', months: 12, bought: '2026-08-01');
        $this->sync();
        self::assertNull($this->reminderFor($plain), 'off by default');
        self::assertNotNull($this->reminderFor($ticked));

        $this->parts()->update($this->golf, $ticked, $this->data($ticked, remind: false));
        $this->sync();
        self::assertNull($this->reminderFor($ticked), 'tick cleared');
    }

    public function testEditingTheWarrantyMovesItAndReopensIt(): void
    {
        $part = $this->part('Clutch kit', months: 24, bought: '2026-03-01');
        $this->sync();
        $before = $this->reminderFor($part);
        self::assertNotNull($before);
        $this->service($this->app, ReminderService::class)->dismiss($before);

        $this->parts()->update($this->golf, $part, $this->data($part, months: 36));
        $this->sync();
        $moved = $this->reminderFor($part);
        self::assertSame($before->id, $moved?->id, 'the same reminder');
        self::assertSame('2029-03-01', $moved->dueOn?->format('Y-m-d'));
        self::assertSame(ReminderStatus::Upcoming, $moved->status, 'a new occurrence opens it again');

        $this->parts()->update($this->golf, $part, $this->data($part, months: null));
        $this->sync();
        self::assertNull($this->reminderFor($part), 'the warranty edited away');
    }

    public function testGoneWhenThePartIsSoldDeletedOrItsWarrantyHasEnded(): void
    {
        $sold = $this->part('Exhaust', months: 24, bought: '2026-03-01', fitOn: '2026-04-01');
        $kept = $this->part('Spare shock', months: 24, bought: '2026-03-01');
        $deleted = $this->part('Cat', months: 24, bought: '2026-03-01');
        $ended = $this->part('Old mount', months: 6, bought: '2026-06-01');
        $this->sync();
        self::assertNotNull($this->reminderFor($sold));
        self::assertNotNull($this->reminderFor($kept), 'a stored spare’s warranty runs on');
        self::assertNotNull($this->reminderFor($ended));

        $removal = new PartRemoval(RemovalOutcome::Sold, '120.000');
        $this->parts()->remove($this->golf, $sold, new DateTimeImmutable('2026-09-01'), null, $removal, new DateTimeZone('UTC'));
        $this->parts()->delete($this->golf, $deleted);
        $this->clock->set(new DateTimeImmutable('2026-12-02T10:00:00Z'));
        $this->sync();

        self::assertNull($this->reminderFor($sold), 'gone: sold');
        self::assertNull($this->reminderFor($deleted), 'deleted');
        self::assertNull($this->reminderFor($ended), 'its warranty ended on 1 Dec: nothing left to check');
        self::assertNotNull($this->reminderFor($kept));
    }

    public function testDoneIsKeptUntilTheWarrantyEndsAndReadsCheckedIt(): void
    {
        $part = $this->part('Clutch kit', months: 24, bought: '2026-03-01');
        $this->sync();
        $reminder = $this->reminderFor($part);
        self::assertNotNull($reminder);

        $html = self::body($this->browser->get('/reminders'));
        self::assertStringContainsString('Warranty ends: Clutch kit', $html);
        self::assertStringContainsString('Checked it', $html, 'Done reads Checked it');
        $link = 'href="/vehicles/' . $this->golf->id . '/parts/' . $part->id . '"';
        self::assertStringContainsString($link, $html, 'links to the part');

        $this->service($this->app, ReminderService::class)->markDone($reminder);
        $this->sync();
        self::assertSame(ReminderStatus::Done, $this->reminderFor($part)?->status);
    }

    public function testNotRaisedForAnArchivedVehicleOrWithThePartsModuleOff(): void
    {
        $part = $this->part('Clutch kit', months: 24, bought: '2026-03-01');
        $this->sync();
        self::assertNotNull($this->reminderFor($part));

        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save([Feature::Fuel, Feature::Maintenance, Feature::Reminders, Feature::Reports]);
        $this->sync();
        self::assertNotNull($this->reminderFor($part), 'kept for when the module returns');
        self::assertStringNotContainsString('Warranty ends', self::body($this->browser->get('/reminders')), 'neither listed');

        $toggles->save(Feature::cases());
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $this->golf);
        $this->sync();
        self::assertNull($this->reminderFor($part), 'archived vehicles raise nothing');
    }

    public function testSentLikeAnyOtherReminder(): void
    {
        // Bought on 25 Oct 2026 with 24 months: it ends on 25 Oct 2028, 15 days after the clock.
        $this->clock->set(new DateTimeImmutable('2028-10-10T10:00:00Z'));
        $this->part('Clutch kit', months: 24, bought: '2026-10-25');
        self::assertSame(1, $this->service($this->app, ScheduledTasks::class)->run()->remindersSent);
        self::assertStringContainsString('Warranty ends: Clutch kit', (string) $this->mail->sent[0]->getSubject());
    }

    public function testTheFormRefusesATickOnAWarrantyThatHasEnded(): void
    {
        $prefs = $this->owner($this->app)->preferences;
        $today = new DateTimeImmutable('2026-09-30');
        $input = ['name' => 'Old mount', 'bought_on' => '2025-01-01', 'warranty_months' => '6', 'warranty_reminder' => '1'];

        $refused = PartForm::parse($input, $prefs, $today);
        self::assertInstanceOf(ValidationErrors::class, $refused);
        self::assertTrue($refused->has('warranty_reminder'));

        $allowed = PartForm::parse(['warranty_months' => '60'] + $input, $prefs, $today);
        self::assertInstanceOf(PartData::class, $allowed);
        self::assertTrue($allowed->warrantyReminder);

        $noWarranty = PartForm::parse(['name' => 'Bulb', 'warranty_reminder' => '1'], $prefs, $today);
        self::assertInstanceOf(PartData::class, $noWarranty);
        self::assertFalse($noWarranty->warrantyReminder, 'the tick means nothing without a warranty');
    }

    private function parts(): PartService
    {
        return $this->service($this->app, PartService::class);
    }

    private function part(
        string $name,
        ?string $brand = null,
        ?int $months = null,
        ?string $km = null,
        ?string $bought = null,
        ?string $fitOn = null,
        ?string $fitKm = null,
        bool $remind = true,
    ): Part {
        $data = new PartData(
            name: $name,
            brand: $brand,
            boughtOn: $bought === null ? null : self::date($bought),
            warrantyMonths: $months,
            warrantyKm: $km === null ? null : $km . '.000',
            warrantyFrom: match (true) {
                $months === null && $km === null => null,
                $bought === null => WarrantyStart::Fitting,
                default => WarrantyStart::Purchase,
            },
            warrantyReminder: $remind,
        );

        return $this->parts()->create(
            $this->golf,
            $data,
            new DateTimeZone('UTC'),
            fitOn: $fitOn === null ? null : self::date($fitOn),
            fitKm: $fitKm === null ? null : $fitKm . '.000',
        );
    }

    private function data(Part $part, ?int $months = -1, bool $remind = true): PartData
    {
        $old = $part->data;
        $months = $months === -1 ? $old->warrantyMonths : $months;

        return new PartData(
            name: $old->name,
            brand: $old->brand,
            boughtOn: $old->boughtOn,
            warrantyMonths: $months,
            warrantyKm: $old->warrantyKm,
            warrantyFrom: $months === null && $old->warrantyKm === null ? null : $old->warrantyFrom,
            warrantyReminder: $remind,
        );
    }

    private function reading(string $km, string $utc): void
    {
        $this->service($this->app, OdometerService::class)->create(
            $this->golf,
            new OdometerReadingData($km, new DateTimeImmutable($utc, new DateTimeZone('UTC'))),
        );
    }

    private function sync(): void
    {
        $this->service($this->app, ReminderSync::class)->sync($this->owner($this->app));
    }

    private function reminderFor(Part $part): ?Reminder
    {
        foreach ($this->reminders($this->app) as $reminder) {
            if ($reminder->source === ReminderSource::Part && $reminder->sourceId === $part->id) {
                return $reminder;
            }
        }

        return null;
    }
}
