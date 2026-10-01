<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\IncidentRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\ExifJpeg;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * The incident pages (spec.md §7.29): logging one with photos kept as
 * taken, adding its repair from the incident page, the link picker, the
 * claims history and its CSV, and the module switched off.
 */
final class IncidentsTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-29T10:00:00Z';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string|list<string>> $overrides
     * @return array<string, string|list<string>>
     */
    private static function form(array $overrides = []): array
    {
        return $overrides + [
            'occurred_on' => '2026-03-14',
            'occurred_at_time' => '08:15',
            'type' => 'parked_damage',
            'fault' => 'not_at_fault',
            'location' => 'Tesco car park',
            'damage_areas' => ['rear', 'left'],
            'severity' => 'minor',
            'write_off_category' => 'none',
            'other_party_name' => 'A. Driver',
            'other_party_insurer' => 'Admiral',
            'claim_status' => 'settled',
            'insurer' => 'Aviva',
            'claim_number' => '4417',
            'excess' => '0',
            'payout' => '1000',
            'ncd_affected' => 'no',
            'status' => 'open',
        ];
    }

    private function upload(string $contents, string $name, string $type): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-incident-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, $type, strlen($contents), UPLOAD_ERR_OK);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function only(App $app, Vehicle $vehicle): Incident
    {
        $incidents = $this->service($app, IncidentRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(1, $incidents);

        return $incidents[0];
    }

    public function testLoggingWithAPhotoKeepsItsExifAndAddingTheRepairLinksItOnce(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/incidents';

        self::assertStringContainsString('/log/new/incident', self::body($browser->get('/log/new')));
        $html = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('name="damage_areas[]"', $html);
        self::assertStringContainsString('kept as taken', $html);

        $photo = ExifJpeg::make(40, 20, 6);
        $files = ['attachments' => [$this->upload($photo, 'bumper.jpg', 'image/jpeg')]];
        $response = $browser->post($base . '/new', self::form(), $files);
        self::assertSame(303, $response->getStatusCode());
        $incident = $this->only($app, $golf);
        self::assertSame([DamageArea::Rear, DamageArea::Left], $incident->data->damageAreas);
        self::assertSame('0.000', $incident->data->claim->excess, '0 is a valid excess');
        self::assertStringEndsWith($base . '/' . $incident->id, $response->getHeaderLine('Location'));

        $files = $this->service($app, AttachmentRepository::class)
            ->listForOwner($golf->id, AttachmentOwner::Incident, $incident->id);
        self::assertCount(1, $files);
        $stored = (string) file_get_contents($this->uploadDir() . '/' . $files[0]->storedPath);
        self::assertSame($photo, $stored, 'stored byte for byte, EXIF and all');
        self::assertTrue(ExifJpeg::hasExif($stored));

        $page = self::body($browser->get($base . '/' . $incident->id));
        self::assertStringContainsString('class="incident-photos"', $page);
        self::assertStringContainsString('A. Driver', $page, 'the owner sees the other party');
        $add = $golf->id . '/maintenance/new?incident=' . $incident->id;
        self::assertStringContainsString($add, $page);

        $form = self::body($browser->get('/vehicles/' . $add));
        self::assertMatchesRegularExpression('/<option value="' . $incident->id . '" selected>/', $form, 'preselected');
        $browser->post('/vehicles/' . $golf->id . '/maintenance/new', [
            'performed_on' => '2026-03-20',
            'category' => 'repair',
            'title' => 'Rear bumper',
            'cost' => '1400',
            'incident_id' => (string) $incident->id,
        ]);
        $repairs = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id);
        self::assertSame($incident->id, $repairs[0]->incidentId);

        $page = self::body($browser->get($base . '/' . $incident->id));
        self::assertStringContainsString('Rear bumper', $page);
        self::assertStringContainsString('£1,400.00', $page, 'linked costs');
        self::assertStringContainsString('£400.00', $page, 'net of the £1,000 payout');
    }

    public function testTheFormRefusesAFutureDateAndATimeAndNameTogetherWithADriver(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $response = $browser->post('/vehicles/' . $golf->id . '/incidents/new', self::form([
            'occurred_on' => '2026-09-30',
            'occurred_at_time' => '25:00',
            'driver_user_id' => (string) $golf->userId,
            'driver_name' => 'Alex',
        ]));
        self::assertSame(422, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('can’t be in the future', $html);
        self::assertStringContainsString('Enter a time such as 08:15', $html);
        self::assertStringContainsString('not both', $html);
        self::assertStringContainsString('value="rear" checked', $html, 'the ticked areas are kept');
    }

    public function testTheInsurerIsFilledFromThePolicyCurrentOnTheDate(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->document($app, $golf, ComplianceType::Insurance, '2025-01-01', '2025-12-31', '500', 'Aviva');
        $this->document($app, $golf, ComplianceType::Insurance, '2026-01-01', '2026-12-31', '520', 'Direct Line');

        $today = self::body($browser->get('/vehicles/' . $golf->id . '/incidents/new'));
        self::assertStringContainsString('value="Direct Line"', $today);
        $earlier = self::body($browser->get('/vehicles/' . $golf->id . '/incidents/new?on=2025-06-01'));
        self::assertStringContainsString('value="Aviva"', $earlier);
        self::assertStringContainsString('value="2025-06-01"', $earlier);
    }

    public function testThePickerLinksARecordInTheWindowAndUnlinksIt(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/incidents/new', self::form());
        $incident = $this->only($app, $golf);
        $hire = $this->expense($app, $golf, '2026-03-16', '45.00', note: 'Hire car');
        $before = $this->expense($app, $golf, '2026-03-01', '9.00', note: 'Parking');
        $base = '/vehicles/' . $golf->id . '/incidents/' . $incident->id;

        $page = self::body($browser->get($base));
        self::assertStringContainsString('value="expense:' . $hire->id . '"', $page);
        self::assertStringNotContainsString('value="expense:' . $before->id . '"', $page, 'before the incident');

        $browser->post($base . '/links', ['record' => 'expense:' . $before->id]);
        $expenses = $this->service($app, ExpenseEntryRepository::class);
        self::assertNull($expenses->find($golf->id, $before->id)?->incidentId, 'outside the window is refused');

        $browser->post($base . '/links', ['record' => 'expense:' . $hire->id]);
        $linked = $expenses->find($golf->id, $hire->id);
        self::assertSame($incident->id, $linked?->incidentId);
        $browser->post($base . '/links', ['unlink' => 'expense:' . $hire->id]);
        $unlinked = $expenses->find($golf->id, $hire->id);
        self::assertNotNull($unlinked, 'kept');
        self::assertNull($unlinked->incidentId, 'and unlinked');
    }

    public function testTheClaimsHistoryListsSoldVehiclesAndNeverTheOtherParty(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $fiesta = $this->vehicle($app, 'Ford', 'Fiesta');
        $browser->post('/vehicles/' . $golf->id . '/incidents/new', self::form());
        $browser->post('/vehicles/' . $fiesta->id . '/incidents/new', self::form([
            'occurred_on' => '2023-05-02',
            'type' => 'collision',
            'fault' => 'at_fault',
            'claim_number' => 'AB-77',
            'write_off_category' => 'cat_s',
        ]));
        $this->service($app, VehicleService::class)->archive($this->owner($app), $fiesta);

        $html = self::body($browser->get('/incidents/history'));
        self::assertStringContainsString('Insurers usually ask about the last 5 years', $html);
        self::assertStringContainsString('AB-77', $html, 'the sold car is listed');
        self::assertStringContainsString('Cat S', $html);
        self::assertStringNotContainsString('A. Driver', $html);
        self::assertStringNotContainsString('Admiral', $html);

        $csv = self::body($browser->get('/incidents/history.csv'));
        self::assertStringContainsString('AB-77', $csv);
        self::assertStringContainsString('4417', $csv);
        self::assertStringNotContainsString('A. Driver', $csv);
        self::assertStringNotContainsString('Admiral', $csv);

        $faulty = self::body($browser->get('/incidents/history.csv?fault=at_fault'));
        self::assertStringContainsString('AB-77', $faulty);
        self::assertStringNotContainsString('4417', $faulty);
    }

    public function testAViewShareSeesTheSummaryButNotTheClaim(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/incidents/new', self::form());
        $incident = $this->only($app, $golf);
        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, true, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'viewer');

        $page = self::body($theirs->get('/vehicles/' . $golf->id . '/incidents/' . $incident->id));
        self::assertStringContainsString('Parked damage', $page);
        self::assertStringContainsString('Rear', $page);
        self::assertStringNotContainsString('4417', $page);
        self::assertStringNotContainsString('A. Driver', $page);
        self::assertStringNotContainsString('Tesco', $page, 'the location is a detail');
        self::assertStringContainsString('Not shared with you', self::body($theirs->get('/incidents/history')));
    }

    public function testWithTheModuleOffEverythingIsGoneAndTheDataKept(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/incidents/new', self::form());
        $incident = $this->only($app, $golf);
        $repair = $this->maintenance($app, $golf, '2026-03-20', 'Rear bumper', '1400.00');
        $this->service($app, IncidentRepository::class)
            ->setLink(LinkKind::Maintenance, $golf->id, $repair->id, $incident->id);

        $toggles = $this->service($app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Incidents)));

        foreach (['/incidents', '/incidents/new', '/incidents/' . $incident->id] as $path) {
            self::assertSame(404, $browser->get('/vehicles/' . $golf->id . $path)->getStatusCode(), $path);
        }
        self::assertSame(404, $browser->get('/incidents/history')->getStatusCode());
        self::assertStringNotContainsString('/incidents', self::body($browser->get('/vehicles/' . $golf->id)));
        self::assertStringNotContainsString('log/new/incident', self::body($browser->get('/log/new')));
        $edit = self::body($browser->get('/vehicles/' . $golf->id . '/maintenance/' . $repair->id . '/edit'));
        self::assertStringNotContainsString('name="incident_id"', $edit);

        // Saving the record leaves its link as it was.
        $browser->post('/vehicles/' . $golf->id . '/maintenance/' . $repair->id . '/edit', [
            'performed_on' => '2026-03-20',
            'category' => 'repair',
            'title' => 'Rear bumper',
            'cost' => '1400',
        ]);
        $kept = $this->service($app, MaintenanceEntryRepository::class)->find($golf->id, $repair->id);
        self::assertSame($incident->id, $kept?->incidentId);
        self::assertSame(ClaimStatus::Settled, $this->only($app, $golf)->data->claim->status, 'kept');
        self::assertSame(Fault::NotAtFault, $this->only($app, $golf)->data->fault);
        self::assertSame(IncidentType::ParkedDamage, $this->only($app, $golf)->data->type);
        self::assertSame(WriteOffCategory::None, $this->only($app, $golf)->data->writeOff);
    }
}
