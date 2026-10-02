<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreSetData;
use Logbook\Domain\User\UserIdentity;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\BackupRepository;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\UserIdentityRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Trip\RateProvider;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\SetChoice;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreCost;
use Logbook\Service\Tyre\TyreSettingsStore;
use Logbook\Service\Tyre\TyreThresholds;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\OwnershipFiles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Tests\Support\ApiClient;
use Logbook\Domain\Station\PlaceData;
use Logbook\Domain\Station\StationData;
use Logbook\Repository\PlaceRepository;
use Logbook\Repository\StationRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;
use ZipArchive;

/**
 * Whole-dataset backup and restore (spec.md §7.13): a backup restores every
 * table and upload exactly, restoring needs an explicit confirmation and
 * leaves a safety backup, and a bad archive changes nothing.
 */
final class BackupTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    /** A valid 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const string PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    private string $backupDir = '';

    /** @var list<string> */
    private array $tempFiles = [];

    /** The API key populate() creates. */
    private string $apiToken = '';

    protected function setUp(): void
    {
        if (!BackupService::isAvailable()) {
            self::markTestSkipped('PHP\'s zip extension is not installed.');
        }
        $this->backupDir = sys_get_temp_dir() . '/logbook-test-backups-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
        parent::tearDown();
    }

    public function testABackupRestoresEveryTableAndFileExactly(): void
    {
        $app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->populate($app);
        $before = $this->snapshot($app);
        self::assertCount(11, $before['files'], 'the photo and ten attachments');
        $owners = array_column($before['tables']['attachments'], 'owner_type');
        sort($owners);
        self::assertSame(
            [
                'compliance', 'expense', 'incident', 'maintenance', 'maintenance',
                'odometer', 'purchase', 'sale', 'trip', 'valuation',
            ],
            $owners,
        );
        self::assertCount(1, $before['tables']['trips'], 'trips travel too (Phase 22)');
        self::assertCount(2, $before['tables']['incidents'], 'incidents travel too (Phase 27.1)');
        self::assertCount(1, $before['tables']['finance_agreements'], 'finance agreements too (Phase 29.1)');
        self::assertCount(1, $before['tables']['finance_payment_events']);
        self::assertCount(1, $before['tables']['settlement_quotes']);
        self::assertCount(2, $before['tables']['stations'], 'stations too (Phase 30.1)');
        self::assertCount(1, $before['tables']['station_favourites']);
        self::assertCount(1, $before['tables']['places']);
        $merged = array_values(array_filter(
            $before['tables']['stations'],
            static fn (array $row): bool => $row['merged_into'] !== null,
        ));
        self::assertCount(1, $merged);
        self::assertGreaterThan((int) $merged[0]['id'], (int) $merged[0]['merged_into'], 'merged into a newer station');
        $links = array_map('strval', array_filter(array_column($before['tables']['fuel_entries'], 'station_id')));
        self::assertContains((string) $merged[0]['merged_into'], $links);
        self::assertSame(['0'], array_map('strval', array_column($before['tables']['finance_agreements'], 'count_in_costs')));
        $wreck = array_values(array_filter(
            $before['tables']['vehicles'],
            static fn (array $v): bool => $v['disposal'] === 'written_off',
        ));
        self::assertCount(1, $wreck, 'a written-off car (Phase 27.2)');
        self::assertNotNull($wreck[0]['disposal_incident_id'], 'with the incident it points at, restored after it');
        self::assertContains('4300.000', array_map(
            static fn (mixed $v): ?string => is_string($v) ? Decimal::round($v, 3) : null,
            array_column($before['tables']['incidents'], 'repair_estimate'),
        ), 'and the estimate');
        self::assertContains('incident', array_column($before['tables']['odometer_readings'], 'source'));
        $links = array_filter(array_column($before['tables']['maintenance_entries'], 'incident_id'));
        self::assertNotEmpty($links, 'with their links');
        self::assertCount(1, $before['tables']['saved_journeys']);
        self::assertCount(2, $before['tables']['mileage_rate_sets']);
        self::assertContains('trips', array_column($before['tables']['settings'], 'name'));
        self::assertCount(1, $before['tables']['vehicle_valuations'], 'valuations travel too');
        self::assertCount(1, $before['tables']['api_keys'], 'API keys too');
        self::assertCount(1, $before['tables']['user_identities'], 'linked sign-in accounts too (Phase 23.1)');
        self::assertContains('document', array_column($before['tables']['odometer_readings'], 'source'));
        self::assertContains('tyre', array_column($before['tables']['odometer_readings'], 'source'));
        self::assertCount(1, $before['tables']['tyre_sets']);
        self::assertCount(2, $before['tables']['tyres']);
        self::assertCount(3, $before['tables']['tyre_changes']);
        self::assertCount(5, $before['tables']['tyre_change_lines']);
        self::assertNotNull($before['tables']['tyre_changes'][2]['maintenance_entry_id'] ?? null, 'the swap links its record');
        self::assertContains('check', array_column($before['tables']['tyre_changes'], 'kind'));
        self::assertContains('7.144', array_map(
            static fn (mixed $v): ?string => is_string($v) ? Decimal::round($v, 3) : null,
            array_column($before['tables']['tyre_change_lines'], 'tread_mm'),
        ), 'the depths travel with the lines');
        self::assertContains('tyres.thresholds', array_column($before['tables']['settings'], 'name'));
        self::assertContains('mm', array_column($before['tables']['users'], 'depth_unit'));
        self::assertContains('9.625000', array_map(
            static fn (mixed $v): ?string => is_string($v) ? Decimal::round($v, 6) : null,
            array_column($before['tables']['fuel_entries'], 'economy_confirmed'),
        ), 'economy confirmations travel with their fill-ups');

        $response = $browser->get('/settings/backup/download');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="logbook-backup-2026-09-27-100000.zip"',
            $response->getHeaderLine('Content-Disposition'),
        );
        $backup = $this->tempFile(self::body($response));

        $zip = new ZipArchive();
        self::assertTrue($zip->open($backup));
        /** @var array{format: string, tables: array<string, int>, files: int} $manifest */
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertSame('logbook-backup', $manifest['format']);
        self::assertSame(2, $manifest['tables']['vehicles']);
        self::assertSame(11, $manifest['files']);
        self::assertFalse($zip->getFromName('database/sessions.json'), 'sessions are never backed up');
        $zip->close();

        // Then things change: a vehicle and a file go, another vehicle arrives.
        $owner = $this->owner($app);
        $vehicles = $this->service($app, VehicleService::class);
        $golf = $vehicles->listFleet($owner)[0];
        $vehicles->removePhoto($owner, $golf);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->expense($app, $polo, '2026-09-20', '12');
        $this->service($app, FeatureToggles::class)->save(Feature::cases());

        // Step 1: upload. Nothing changes yet.
        $response = $browser->post('/settings/backup/restore', [], ['backup' => $this->upload($backup, 'backup.zip')]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        $confirm = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/settings/backup/restore/[a-f0-9]{32}$#', $confirm);
        $html = self::body($browser->get($confirm));
        self::assertStringContainsString('Restore this backup?', $html);
        self::assertStringContainsString('This backup holds API keys.', $html);
        self::assertStringContainsString('name="confirm" value="1"', $html);

        // Step 2 needs the box ticked.
        $response = $browser->post($confirm, []);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Tick the box', self::body($response));
        self::assertCount(3, $this->ownedVehicles($app, $owner->id));

        $response = $browser->post($confirm, ['confirm' => '1']);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertStringContainsString('The backup was restored.', self::body($browser->follow($response)));
        self::assertSame(303, $browser->get('/')->getStatusCode(), 'everyone is signed out');

        self::assertEquals($before, $this->snapshot($app), 'every row and file as it was');
        self::assertSame(
            200,
            (new ApiClient($app, $this->apiToken))->get('/me')->getStatusCode(),
            'the same SESSION_SECRET: the key still works',
        );

        // The data from before the restore was kept, just in case.
        $safety = glob($this->backupDir . '/pre-restore-*.zip') ?: [];
        self::assertCount(1, $safety);
        self::assertSame(3, $this->service($app, BackupService::class)->inspect($safety[0])->rows('vehicles'));

        // New rows get new ids (PostgreSQL's sequences were moved on).
        $browser->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        $again = $this->vehicle($app, 'Skoda', 'Fabia');
        self::assertGreaterThan($golf->id, $again->id);
        self::assertSame(200, $browser->get('/vehicles/' . $again->id)->getStatusCode());
    }

    public function testEveryTableIsBackedUpOrDeliberatelyLeftOut(): void
    {
        $app = $this->createApp();
        $tables = $this->service($app, BackupRepository::class)->tableNames();
        $known = [...BackupRepository::TABLES, ...BackupRepository::EXCLUDED];

        self::assertSame([], array_values(array_diff($tables, $known)), 'a new table needs a place in BackupRepository::TABLES');
        self::assertSame([], array_values(array_diff($known, $tables)));
    }

    public function testTheCommandLineMakesTheSameBackups(): void
    {
        $app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->populate($app);
        $before = $this->snapshot($app);

        $backups = $this->service($app, BackupService::class);
        $file = $this->backupDir . '/nightly.zip';
        mkdir($this->backupDir);
        $manifest = $backups->create($file);
        self::assertSame(11, $manifest->files);

        $this->resetDatabase($app);
        foreach ($this->service($app, FileStorage::class)->all() as $relative) {
            $this->service($app, FileStorage::class)->delete($relative);
        }
        self::assertSame([], $this->snapshot($app)['files']);

        $backups->restore($file);
        self::assertEquals($before, $this->snapshot($app));
    }

    public function testABadArchiveChangesNothing(): void
    {
        $app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->populate($app);
        mkdir($this->backupDir);
        $good = $this->backupDir . '/good.zip';
        $this->service($app, BackupService::class)->create($good);
        $before = $this->snapshot($app);

        $cases = [
            'This is not a ZIP file.' => $this->tempFile('just text'),
            'This ZIP file is not a Logbook backup.' => $this->variant(
                $good,
                static fn (ZipArchive $z) => $z->deleteName('manifest.json'),
            ),
            'whose database differs from this one' => $this->variant($good, static function (ZipArchive $zip): void {
                $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
                assert(is_array($manifest));
                $manifest['schema_version'] = '20200101000000';
                $manifest['app_version'] = '0.1.0';
                $zip->addFromString('manifest.json', (string) json_encode($manifest));
            }),
            'does not belong in it (uploads/../../evil.php)' => $this->variant(
                $good,
                static fn (ZipArchive $z) => $z->addFromString('uploads/../../evil.php', '<?php'),
            ),
            'damaged or incomplete' => $this->variant($good, static function (ZipArchive $zip): void {
                /** @var array{columns: list<string>, rows: list<list<string|null>>} $table */
                $table = json_decode((string) $zip->getFromName('database/vehicles.json'), true);
                $table['columns'][1] = 'is_admin';
                $zip->addFromString('database/vehicles.json', (string) json_encode($table));
            }),
        ];
        foreach ($cases as $message => $file) {
            $response = $browser->post('/settings/backup/restore', [], ['backup' => $this->upload($file, 'backup.zip')]);
            self::assertSame(422, $response->getStatusCode(), $message);
            self::assertStringContainsString(htmlspecialchars($message, ENT_QUOTES), self::body($response));
        }

        self::assertEquals($before, $this->snapshot($app));
        self::assertSame([], glob($this->backupDir . '/pre-restore-*.zip') ?: []);
    }

    public function testARestoreBelongsToTheSessionThatUploadedIt(): void
    {
        $app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        mkdir($this->backupDir);
        $file = $this->backupDir . '/b.zip';
        $this->service($app, BackupService::class)->create($file);

        $confirm = $browser->post('/settings/backup/restore', [], ['backup' => $this->upload($file, 'b.zip')])
            ->getHeaderLine('Location');
        $other = new TestBrowser($app);
        $other->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        self::assertSame(404, $other->get($confirm)->getStatusCode());
        self::assertSame(404, $other->post($confirm, ['confirm' => '1'])->getStatusCode());
        self::assertSame(200, $browser->get($confirm)->getStatusCode());
    }

    /**
     * A little of everything, with a photo and an attachment on disk.
     *
     * @param App<ContainerInterface> $app
     */
    private function populate(App $app): void
    {
        $owner = $this->owner($app);
        $golf = $this->vehicle($app);
        $photo = $this->upload($this->tempFile((string) base64_decode(self::PNG)), 'golf.png');
        $vehicles = $this->service($app, VehicleService::class);
        // The vehicle details columns are backed up and restored like any other (Phase 9.1).
        $registered = LocalTime::parseDate('2019-03-14');
        assert($registered !== null);
        $golf = $vehicles->update($owner, $golf, new VehicleData(
            $golf->data->type,
            $golf->data->make,
            $golf->data->model,
            $golf->data->fuelType,
            registration: $golf->data->registration,
            variant: '1.5 TSI Life',
            firstRegisteredOn: $registered,
            purchaseDate: LocalTime::parseDate('2021-05-01'),
            saleDate: LocalTime::parseDate('2026-09-25'),
        ), new OwnershipFiles(
            // Purchase and sale paperwork (Phase 12) are rows and files like any other.
            $this->files([[self::PDF, 'purchase-invoice.pdf']]),
            $this->files([[self::PDF, 'sale-receipt.pdf']]),
        ));
        $vehicles->replacePhoto($owner, $golf, $photo, FileUpload::check($photo, 1024 * 1024, UploadKind::Image));

        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '1000.5', '40.123', '60.18', true);
        // The grade column is backed up and restored like any other (Phase 8).
        $graded = $this->fillUp($app, $golf, '2026-09-08T08:00:00Z', '1400', '38.5', '57.75', grade: FuelGrade::E5_97);
        // So is an economy confirmation (Phase 13).
        $this->service($app, FuelEntryRepository::class)->setEconomyConfirmed($golf->id, $graded->id, '9.625000');
        $service = $this->maintenance($app, $golf, '2026-09-14', 'Annual service', '189.5', '1609.344');
        $zone = new DateTimeZone('Europe/London');
        // Several files per save, on every owner type (Phase 10).
        $this->service($app, MaintenanceService::class)->update($golf, $service, $service->data, $zone, $this->files([
            [self::PDF, 'invoice.pdf'],
            [(string) base64_decode(self::PNG), 'odometer.png'],
        ]));
        $expense = $this->expense($app, $golf, '2026-09-20', '0', note: 'Free, “for once”');
        $this->service($app, ExpenseService::class)
            ->update($golf, $expense, $expense->data, $this->files([[self::PDF, 'parking.pdf']]));
        // A valuation and its screenshot (Phase 14.1).
        $this->service($app, ValuationService::class)->create(
            $golf,
            new VehicleValuationData(new DateTimeImmutable('2026-09-10'), '9800.500', 'Auto Trader', 'Online'),
            $this->files([[(string) base64_decode(self::PNG), 'quote.png']]),
        );
        $reading = $this->service($app, OdometerService::class)->create(
            $golf,
            new OdometerReadingData('1700', new DateTimeImmutable('2026-09-21T08:00:00Z')),
            $this->files([[(string) base64_decode(self::PNG), 'dashboard.png']]),
        );
        self::assertTrue($reading->isManual());
        // The document odometer and its `document` reading (Phase 10).
        $this->service($app, ComplianceService::class)->create($golf, new ComplianceDocumentData(
            ComplianceType::Inspection,
            startOn: LocalTime::parseDate('2026-09-02'),
            odometerKm: '1650.000',
        ), $zone, $this->files([[self::PDF, 'mot.pdf']]));
        // Tyres (Phase 11.1): the four tables, a `tyre` reading and a change linked to its service record.
        $tyres = $this->service($app, TyreChangeService::class);
        $day = static fn (string $date): DateTimeImmutable => LocalTime::parseDate($date) ?? throw new \LogicException($date);
        $primacy = new TyreData('Michelin', 'Primacy 4', '205/55 R16 91V');
        // Tread depths (Phase 11.2): on the lines, a check, and the thresholds setting.
        $michelin = static fn (TyrePosition $p): NewTyre => new NewTyre($p, $primacy, '7.938');
        $existing = $tyres->existing($golf, new TyreChangeData($day('2026-09-01'), '1000.500'), [
            $michelin(TyrePosition::FrontLeft),
            $michelin(TyrePosition::FrontRight),
        ], $zone, 'en_GB');
        $tyres->check($golf, new TyreChangeData($day('2026-09-10'), '1500.000'), [
            $existing->tyreIds()[0] => '7.144',
        ], $zone, 'en_GB');
        $this->service($app, TyreSettingsStore::class)->saveThresholds($owner->id, new TyreThresholds(ageYears: 5));
        $tyres->swap(
            $golf,
            new TyreChangeData($day('2026-09-15'), '1620.000'),
            new SetChoice(newSet: new TyreSetData('Summer wheels', 'Garage loft')),
            [],
            new TyreCost('25.000', 'Kwik Fit'),
            $zone,
            'en_GB',
        );
        // Trips (Phase 22): a trip with its toll receipt, a saved journey and the GB rates.
        $this->service($app, TripService::class)->create($golf, new TripData(
            travelledOn: $day('2026-09-18'),
            fromPlace: 'Ballymena',
            toPlace: 'Belfast',
            isReturn: true,
            distanceKm: '173.810',
            odometerStartKm: '1600.000',
            odometerEndKm: '1773.810',
            purpose: 'Client meeting',
            passengers: 2,
        ), $this->files([[(string) base64_decode(self::PNG), 'toll.png']]), saveJourney: true);
        self::assertTrue($this->service($app, RateProvider::class)->ensure($owner));
        // Incidents (Phase 27.1): one with a reading and a photo, the service record linked.
        $incident = $this->service($app, IncidentService::class)->create($golf, new IncidentData(
            occurredOn: $day('2026-09-12'),
            type: IncidentType::ParkedDamage,
            damageAreas: [DamageArea::Rear],
            otherPartyName: 'A. Driver',
            claim: new Claim(ClaimStatus::Settled, 'Aviva', claimNumber: '4417', payout: '100.000'),
        ), '1600.000', $zone, $this->files([[(string) base64_decode(self::PNG), 'scrape.png']]));
        $this->service($app, IncidentService::class)->link($golf, LinkKind::Maintenance, $service->id, $incident);
        // Phase 27.2: a car written off by its incident (the vehicle points at an incident restored after it).
        $wreck = $this->service($app, VehicleService::class)->create($owner, new VehicleData(
            VehicleType::Car,
            'Ford',
            'Fiesta',
            FuelType::Petrol,
            registration: 'WR14 FNE',
        ));
        $loss = $this->service($app, IncidentService::class)->create($wreck, new IncidentData(
            occurredOn: $day('2026-08-01'),
            type: IncidentType::Collision,
            damageAreas: [DamageArea::Front],
            writeOff: WriteOffCategory::CatS,
            claim: new Claim(ClaimStatus::Settled, 'Aviva', payout: '2100.000', repairEstimate: '4300.000'),
        ), null, $zone);
        $this->service($app, VehicleService::class)->archiveWrittenOff($owner, $wreck, $loss->id, $day('2026-08-20'), '2100.000');
        // Phase 29.1: a PCP with a missed payment and a settlement quote.
        $finance = $this->service($app, FinanceAgreementRepository::class);
        $at = new \DateTimeImmutable(self::NOW);
        $agreement = $finance->insert($golf->id, new AgreementData(
            type: AgreementType::Pcp,
            lender: 'Volkswagen Financial Services',
            agreementNumber: 'VWFS-1',
            startedOn: $day('2025-03-01'),
            firstPaymentOn: $day('2025-04-01'),
            numberOfPayments: 36,
            regularPayment: '250.000',
            finalPayment: '9000.000',
            cashPrice: '22000.000',
            customerDeposit: '2000.000',
            apr: '6.900',
            countInCosts: false,
        ), $at, $owner->id);
        $finance->insertEvent($agreement, PaymentEventKind::Missed, $day('2026-06-01'), null, null, null, $at);
        $finance->insertQuote($agreement, $day('2026-09-01'), '16000.000', $day('2026-09-30'), null, $at);
        $due = LocalTime::parseDate('2026-10-01');
        assert($due !== null);
        $this->service($app, ReminderService::class)->createManual($owner, new ManualReminderData($golf->id, 'Wash', $due, 7));
        // Phase 30.1: a station merged into a newer one (restored after it), a favourite, a place,
        // and a fill-up linked.
        $stations = $this->service($app, StationRepository::class);
        $old = $stations->insert(new StationData('Shell Antrim', latitude: '54.715400', longitude: '-6.216400'), $owner->id, $at);
        $kept = $stations->insert(new StationData('Shell, Antrim', 'Shell', grades: [FuelGrade::E10_95]), $owner->id, $at);
        $stations->merge($old, $kept, $at);
        $stations->setFavourite($owner->id, $kept, true, $at);
        $this->service($app, PlaceRepository::class)->insert($owner->id, new PlaceData('Home', '54.706400', '-6.216400'), $at);
        $fuelEntries = $this->service($app, FuelEntryRepository::class);
        $linked = $fuelEntries->listForVehicle($golf->id)[0];
        $fuelEntries->update($golf->id, $linked->id, $linked->data->withStation($kept, 'Shell, Antrim'), $at);
        $this->service($app, FeatureToggles::class)->save([Feature::Fuel, Feature::Maintenance, Feature::Reminders]);
        // API keys (Phase 18.2) travel too, as their keyed hashes.
        $this->apiToken = $this->service($app, ApiKeyService::class)->create($owner, 'Home Assistant', ApiScope::Read)->token;
        // Phase 23.1: a linked single sign-on account.
        $this->service($app, UserIdentityRepository::class)
            ->insert($owner->id, UserIdentity::OIDC, 'https://auth.example.com/', 'sub-owner', new \DateTimeImmutable(self::NOW));
    }

    /**
     * @param list<array{string, string}> $files contents and name
     */
    private function files(array $files): PendingUploads
    {
        $pending = [];
        foreach ($files as [$contents, $name]) {
            $file = $this->upload($this->tempFile($contents), $name);
            $pending[] = new PendingUpload($file, FileUpload::check($file, 1024 * 1024, UploadKind::Document));
        }

        return new PendingUploads($pending);
    }

    /**
     * Every backed-up row, and every stored file's contents.
     *
     * @param App<ContainerInterface> $app
     * @return array{tables: array<string, list<array<string, string|null>>>, files: array<string, string>}
     */
    private function snapshot(App $app): array
    {
        $repository = $this->service($app, BackupRepository::class);
        $tables = [];
        foreach (BackupRepository::TABLES as $table) {
            $tables[$table] = $repository->rows($table);
        }
        $storage = $this->service($app, FileStorage::class);
        $files = [];
        foreach ($storage->all() as $relative) {
            $files[$relative] = hash_file('sha256', $storage->absolutePath($relative)) ?: '';
        }

        return ['tables' => $tables, 'files' => $files];
    }

    /**
     * A copy of $source changed by $change.
     *
     * @param callable(ZipArchive): mixed $change
     */
    private function variant(string $source, callable $change): string
    {
        $copy = $this->tempFile((string) file_get_contents($source));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($copy));
        $change($zip);
        $zip->close();

        return $copy;
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-backup-test-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function upload(string $path, string $name): UploadedFile
    {
        // A copy, since the app moves uploads away.
        $copy = $this->tempFile((string) file_get_contents($path));

        return new UploadedFile($copy, $name, 'application/octet-stream', (int) filesize($copy), UPLOAD_ERR_OK);
    }
}
