<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Incident;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Incident\Severity;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attention\AttentionHiding;
use Logbook\Service\Attention\AttentionItem;
use Logbook\Service\Attention\AttentionList;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;
use ZipArchive;

/**
 * Incidents everywhere else (spec.md §7.29): a linked repair counted once
 * in Reports and ownership, ownership net of payouts, the sale pack's
 * incident group and write-off line, ZIP photos without EXIF, History and
 * its print view, and the stalled-claim item in *Needs attention*.
 */
final class IncidentFeaturesTest extends AppTestCase
{
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private MutableClock $clock;
    private TestBrowser $browser;
    private User $owner;
    private Vehicle $golf;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->clock = $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->browser = $this->signedIn($this->app);
        $this->owner = $this->owner($this->app);
        $this->golf = $this->vehicle($this->app);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        assert($day !== null);

        return $day;
    }

    private function incidents(): IncidentService
    {
        return $this->service($this->app, IncidentService::class);
    }

    private function log(
        string $date = '2026-03-14',
        Claim $claim = new Claim(),
        WriteOffCategory $writeOff = WriteOffCategory::None,
        IncidentType $type = IncidentType::ParkedDamage,
        PendingUploads $files = new PendingUploads(),
    ): Incident {
        return $this->incidents()->create($this->golf, new IncidentData(
            occurredOn: self::day($date),
            type: $type,
            location: 'Tesco car park',
            fault: Fault::AtFault,
            damageAreas: [DamageArea::Rear],
            severity: Severity::Minor,
            driverName: 'Alex Driver',
            otherPartyName: 'Other Person',
            policeReference: 'PR-9911',
            writeOff: $writeOff,
            claim: $claim,
        ), null, new DateTimeZone('Europe/London'), $files);
    }

    private function photo(string $bytes): PendingUploads
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-incident-');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;
        $file = new UploadedFile($path, 'bumper.jpg', 'image/jpeg', strlen($bytes), UPLOAD_ERR_OK);
        $checked = $this->service($this->app, AttachmentService::class)->check($file, AttachmentOwner::Incident);

        return new PendingUploads([new PendingUpload($file, $checked)]);
    }

    /**
     * @return list<AttentionItem>
     */
    private function stalled(): array
    {
        return array_values(array_filter(
            $this->service($this->app, AttentionList::class)->forVehicles($this->owner, [$this->golf])->items,
            static fn (AttentionItem $item): bool => $item->kind === AttentionKind::StalledClaim,
        ));
    }

    public function testALinkedRepairIsCountedOnceInReportsAndTheSectionShowsItAndThePayout(): void
    {
        $repair = $this->maintenance($this->app, $this->golf, '2026-03-20', 'Rear bumper', '1400.00', '42000');
        $incident = $this->log(claim: new Claim(ClaimStatus::Settled, 'Aviva', claimNumber: '4417', payout: '1000.000'));
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, $incident);

        $html = self::body($this->browser->get('/reports?range=all'));
        self::assertStringContainsString('£1,400.00', $html);
        self::assertStringContainsString('Incident-related spend', $html);
        self::assertStringContainsString('£1,000.00', $html, 'payouts received');
        self::assertStringNotContainsString('£2,800.00', $html, 'never counted twice');
    }

    public function testOwnershipIsNetOfPayoutsWithTheLine(): void
    {
        $this->service($this->app, VehicleService::class)->update($this->owner, $this->golf, new VehicleData(
            $this->golf->data->type,
            $this->golf->data->make,
            $this->golf->data->model,
            $this->golf->data->fuelType,
            registration: $this->golf->data->registration,
            purchaseDate: self::day('2025-01-01'),
        ));
        $golf = $this->service($this->app, VehicleService::class)->get($this->owner, $this->golf->id);
        $this->maintenance($this->app, $golf, '2026-03-20', 'Rear bumper', '1400.00');
        $this->log(claim: new Claim(ClaimStatus::Settled, payout: '1000.000'));

        $cost = $this->service($this->app, OwnershipService::class)->forVehicle(
            $this->owner,
            $golf,
            [],
            Depreciation::of($golf, [], [], self::day('2026-09-29'), new DateTimeZone('Europe/London'), 'GBP'),
            self::day('2026-09-29'),
        );
        self::assertNotNull($cost);
        self::assertSame('400.00', $cost->running->toDecimal(2), 'net of the payout');
        self::assertSame('1000.00', $cost->payouts?->toDecimal(2));

        $html = self::body($this->browser->get('/vehicles/' . $golf->id));
        self::assertStringContainsString('Insurance payouts', $html);
        $csv = self::body($this->browser->get('/reports/ownership.csv'));
        self::assertStringContainsString('Insurance payouts', $csv);
    }

    public function testTheSalePackShowsRepairsButNeverTheClaimAndTellsTheSellerAboutAWriteOff(): void
    {
        $repair = $this->maintenance($this->app, $this->golf, '2025-03-20', 'Rear quarter panel', '2400.00');
        $claim = new Claim(ClaimStatus::Settled, 'Aviva', claimNumber: '4417', payout: '2000.000');
        $incident = $this->log('2025-03-14', $claim, WriteOffCategory::CatS);
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, $incident);
        $base = '/vehicles/' . $this->golf->id . '/sale-pack';

        $off = self::body($this->browser->get($base));
        self::assertStringNotContainsString('Rear: Minor', $off);
        self::assertStringContainsString('This vehicle has a Cat S record', $off, 'the seller is told');
        self::assertStringNotContainsString('Recorded as Cat S', $off);
        self::assertStringNotContainsString('Part of', $off);

        $on = self::body($this->browser->get($base . '?options=1&incidents=1&descriptions=1'));
        self::assertStringContainsString('Recorded as Cat S (14 Mar 2025)', $on);
        self::assertStringContainsString('Repaired: Rear quarter panel', $on);
        self::assertStringNotContainsString('This vehicle has a Cat S record', $on);
        foreach (['4417', 'Aviva', 'Alex Driver', 'Other Person', 'PR-9911', 'Tesco', 'At fault', '£2,000'] as $never) {
            self::assertStringNotContainsString($never, $on, $never);
        }
    }

    public function testZipPhotosLeaveWithoutExifWhileTheStoredOneKeepsIt(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('Needs the zip extension to read the ZIP back.');
        }
        $photo = ExifJpeg::make(40, 20, 6);
        $incident = $this->log(files: $this->photo($photo));
        $files = $this->service($this->app, AttachmentRepository::class)
            ->listForOwner($this->golf->id, AttachmentOwner::Incident, $incident->id);
        $stored = (string) file_get_contents($this->uploadDir() . '/' . $files[0]->storedPath);
        self::assertSame($photo, $stored, 'kept as taken');

        $page = self::body($this->browser->get('/vehicles/' . $this->golf->id . '/sale-pack'));
        self::assertStringNotContainsString('value="incident_photos"', $page, 'not offered without incidents');

        $query = ['options' => '1', 'incidents' => '1', 'kinds' => ['incident_photos']];
        $response = $this->browser->get('/vehicles/' . $this->golf->id . '/sale-pack/paperwork.zip?' . http_build_query($query));
        self::assertSame(200, $response->getStatusCode());
        $path = $this->uploadDir() . '/out-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($path, self::body($response));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CHECKCONS), 'sizes and CRCs match what was written');
        $names = [];
        $jpeg = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $names[] = $name;
            if (str_ends_with($name, '.jpg')) {
                $jpeg = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        unlink($path);

        self::assertNotNull($jpeg, implode(', ', $names));
        self::assertFalse(ExifJpeg::hasExif($jpeg), 'no EXIF, so no GPS, leaves');
        self::assertSame([20, 40], array_slice((array) getimagesizefromstring($jpeg), 0, 2), 'turned upright');
        $stillStored = (string) file_get_contents($this->uploadDir() . '/' . $files[0]->storedPath);
        self::assertSame($photo, $stillStored, 'the stored file is unchanged');
    }

    public function testHistoryListsTheIncidentWithItsRecordsAndPrintLeavesItOutUnlessTicked(): void
    {
        $repair = $this->maintenance($this->app, $this->golf, '2026-03-20', 'Rear bumper', '1400.00');
        $incident = $this->log();
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, $incident);
        $base = '/vehicles/' . $this->golf->id . '/history';

        $all = self::body($this->browser->get($base . '?year=2026'));
        self::assertStringContainsString('Parked damage', $all);
        self::assertStringContainsString('Rear bumper (20 Mar 2026)', $all, 'its linked record on its second line');
        self::assertStringContainsString('Part of: Parked damage, 14 Mar 2026', $all);
        self::assertStringNotContainsString('Alex Driver', $all);

        $chip = self::body($this->browser->get($base . '?kind=incidents&year=2026'));
        self::assertStringContainsString('Parked damage', $chip);

        $print = self::body($this->browser->get($base . '/print'));
        self::assertStringNotContainsString('Parked damage', $print, 'off until ticked');
        self::assertStringContainsString('Rear bumper', $print);
        $ticked = self::body($this->browser->get($base . '/print?options=1&kinds[]=service&kinds[]=incidents'));
        self::assertStringContainsString('Parked damage', $ticked);
        self::assertStringContainsString('Part of: Parked damage', $ticked);
    }

    public function testAStalledClaimIsRaisedOnDay31AndGoesWithNewsOrAHide(): void
    {
        $incident = $this->log(
            '2026-08-01',
            new Claim(ClaimStatus::Open, 'Aviva', claimNumber: '4417', updatedOn: self::day('2026-08-30')),
        );

        $before = $this->stalled();
        self::assertSame([], $before, '30 days without news: not yet');
        $this->clock->set(new DateTimeImmutable('2026-09-30T10:00:00Z'));
        $items = $this->stalled();
        self::assertCount(1, $items, 'raised on day 31');
        $html = self::body($this->browser->get('/vehicles/' . $this->golf->id));
        self::assertStringContainsString('Claim 4417 with Aviva: no update for 31 days', $html);

        $hiding = $this->service($this->app, AttentionHiding::class);
        $fingerprint = (string) $items[0]->fingerprint;
        self::assertTrue($hiding->hide($this->owner, $this->golf, 'stalled_claim', $incident->id, $fingerprint));
        $hidden = $this->stalled();
        self::assertSame([], $hidden, 'hidden');

        // News brings a new fingerprint: the item would return if still waiting, and goes once settled.
        $this->incidents()->update($this->golf, $incident, new IncidentData(
            occurredOn: $incident->data->occurredOn,
            type: $incident->data->type,
            claim: new Claim(ClaimStatus::Settled, 'Aviva', claimNumber: '4417', updatedOn: self::day('2026-08-30')),
        ), null, new DateTimeZone('Europe/London'));
        self::assertSame([], $this->stalled(), 'settled');
    }

    public function testPayoutsAreAClaimDetailInReportsAndOwnership(): void
    {
        $repair = $this->maintenance($this->app, $this->golf, '2026-03-20', 'Rear bumper', '1400.00');
        $incident = $this->log(claim: new Claim(ClaimStatus::Settled, payout: '1000.000'));
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, $incident);
        $logger = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $logger->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));

        $theirs = self::body($this->browserFor($this->app, 'logger')->get('/reports?range=all'));
        self::assertStringContainsString('£1,400.00', $theirs, 'they see costs');
        self::assertStringNotContainsString('£1,000.00', $theirs, 'but not the payout');

        $payouts = $this->service($this->app, OwnershipService::class)->payouts($logger, $this->golf);
        self::assertSame([], $payouts, 'running costs stay gross for them');
        self::assertCount(1, $this->service($this->app, OwnershipService::class)->payouts($this->owner, $this->golf));
    }

    public function testThePickerOffersOnlyIncidentsTheUserMayChangeAndKeepsOthersLinks(): void
    {
        $owners = $this->log();
        $logger = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $logger->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $browser = $this->browserFor($this->app, 'logger');
        $base = '/vehicles/' . $this->golf->id . '/expenses';

        $form = self::body($browser->get($base . '/new'));
        self::assertStringNotContainsString('name="incident_id"', $form, 'the owner\'s incident is not theirs to link to');

        $entry = ['spent_on' => '2026-03-15', 'category' => 'parking', 'amount' => '3'];
        $browser->post($base . '/new', $entry + ['incident_id' => (string) $owners->id]);
        $expenses = $this->service($this->app, ExpenseEntryRepository::class)->listForVehicle($this->golf->id);
        self::assertNull($expenses[0]->incidentId, 'a forged choice links nothing');

        // A link the owner made stays when the logger edits their own record.
        $this->incidents()->link($this->golf, LinkKind::Expense, $expenses[0]->id, $owners);
        $browser->post($base . '/' . $expenses[0]->id . '/edit', ['amount' => '4', 'incident_id' => ''] + $entry);
        $kept = $this->service($this->app, ExpenseEntryRepository::class)->find($this->golf->id, $expenses[0]->id);
        self::assertSame($owners->id, $kept?->incidentId);
    }

    public function testNewsWithin30DaysClearsAStalledClaim(): void
    {
        $claim = new Claim(ClaimStatus::Open, 'Aviva', claimNumber: '4417', updatedOn: self::day('2026-07-01'));
        $incident = $this->log('2026-07-01', $claim);
        $before = $this->stalled();
        self::assertCount(1, $before);

        $this->incidents()->update($this->golf, $incident, new IncidentData(
            occurredOn: $incident->data->occurredOn,
            type: $incident->data->type,
            claim: new Claim(ClaimStatus::Open, 'Aviva', claimNumber: '4417', updatedOn: self::day('2026-09-20')),
        ), null, new DateTimeZone('Europe/London'));
        $after = $this->stalled();
        self::assertSame([], $after, 'news nine days ago');
    }

    public function testWithTheModuleOffTheChipPackOptionReportsSectionAndCheckAreGone(): void
    {
        $repair = $this->maintenance($this->app, $this->golf, '2026-03-20', 'Rear bumper', '1400.00');
        $incident = $this->log('2026-07-01', new Claim(ClaimStatus::Open, 'Aviva', claimNumber: '4417', payout: '50.000'));
        $this->incidents()->link($this->golf, LinkKind::Maintenance, $repair->id, $incident);
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Incidents)));
        $id = $this->golf->id;

        $history = self::body($this->browser->get('/vehicles/' . $id . '/history'));
        self::assertStringNotContainsString('kind=incidents', $history);
        $year = self::body($this->browser->get('/vehicles/' . $id . '/history?year=2026'));
        self::assertStringNotContainsString('Part of:', $year);
        $pack = self::body($this->browser->get('/vehicles/' . $id . '/sale-pack'));
        self::assertStringNotContainsString('name="incidents"', $pack);
        self::assertStringNotContainsString('Incident-related spend', self::body($this->browser->get('/reports?range=all')));
        $stalled = $this->stalled();
        self::assertSame([], $stalled);
        self::assertStringNotContainsString('Insurance payouts', self::body($this->browser->get('/vehicles/' . $id)));
    }
}
