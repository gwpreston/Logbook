<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\Scan\ScanStatus;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\PendingUploadRepository;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Ai\AiHousekeeping;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Domain\Feature\Feature;
use Logbook\Support\Storage\FileStorage;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\ScanFiles;
use Logbook\Tests\Support\ScanFixture;
use Logbook\Tests\Support\ScanTestCase;
use Logbook\Tests\Support\ScriptedProvider;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Reading files end to end (spec.md §7.27, Phase 26.4 *Tests* and
 * *Acceptance criteria*): scan, the prefilled form, save with the file
 * attached once, the recommendations card, failures that keep the file,
 * pending uploads that are their user's only and expire, the V5C page,
 * availability, and a document that tries to give instructions.
 */
final class ScanFlowTest extends ScanTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->scanApp();
    }

    /**
     * Acceptance 1: a photo of a garage invoice opens a service record with
     * date, vehicle, mileage, garage, work, cost and VAT filled in and
     * marked, and the photo attached. Saving creates one record with one
     * attachment, upright and without its GPS.
     */
    public function testAnInvoicePhotoBecomesOneRecordWithOneAttachment(): void
    {
        $golf = $this->garage['Golf'];
        [$form, $token] = $this->scanToForm($this->invoicePhoto(), $this->invoiceReply());
        $values = Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'));
        self::assertSame('2026-09-12', $values['performed_on']);
        self::assertSame('48120', $values['odometer']);
        self::assertSame('Brightwater Motors Ltd', $values['vendor']);
        self::assertSame('Full service', $values['title']);
        self::assertSame('184.5', $values['cost']);
        self::assertStringContainsString('VAT £30.75 (20%)', $values['description']);
        self::assertStringContainsString('“Total due £184.50”', self::body($form), 'its evidence as a hint');
        self::assertStringContainsString('invoice.jpg will be attached.', self::body($form));
        self::assertSame(0, $this->rows('maintenance_entries'), 'nothing is saved by reading');

        $pending = $this->pending($token);
        self::assertNotNull($pending->storedPath);
        $pendingFile = $this->uploadDir() . '/' . $pending->storedPath;
        self::assertFileExists($pendingFile);

        $saved = $this->browser->post('/vehicles/' . $golf->id . '/maintenance/new', $values);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        self::assertSame('/scan/' . $token . '/reminders', $saved->getHeaderLine('Location'), 'on to the recommendations');

        $entries = $this->service($this->app, MaintenanceEntryRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $entries);
        $files = $this->service($this->app, AttachmentRepository::class)
            ->listForOwner($golf->id, AttachmentOwner::Maintenance, $entries[0]->id);
        self::assertCount(1, $files);
        self::assertSame('invoice.jpg', $files[0]->filename);
        $stored = (string) file_get_contents($this->uploadDir() . '/' . $files[0]->storedPath);
        self::assertFalse(ExifJpeg::hasExif($stored), 'the stored file has no GPS');
        self::assertSame([20, 40], array_slice((array) getimagesizefromstring($stored), 0, 2), 'turned upright');
        self::assertFileDoesNotExist($pendingFile, 'the pending file goes once the entry is saved');
        self::assertSame(ScanStatus::Saved, $this->pending($token)->status);

        // The same form again (a double submit) saves without the file.
        $again = $this->browser->post('/vehicles/' . $golf->id . '/maintenance/new', $values);
        self::assertSame(303, $again->getStatusCode());
        self::assertSame(1, $this->rows('attachments'), 'the file is attached once');
    }

    /**
     * Recommended work is offered, never added on its own; a distance is
     * kept as a distance, labelled with the projected date.
     */
    public function testRecommendedWorkIsOfferedAsRemindersAfterSaving(): void
    {
        $golf = $this->garage['Golf'];
        $this->readings($golf, ['2026-08-01T09:00' => '47500', '2026-09-01T09:00' => '47810']);
        [$form, $token] = $this->scanToForm($this->invoicePhoto(), $this->invoiceReply());
        $values = Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'));
        $this->browser->post('/vehicles/' . $golf->id . '/maintenance/new', $values);
        self::assertSame([], $this->manualReminders(), 'nothing is added before a press');

        $card = self::body($this->browser->get('/scan/' . $token . '/reminders'));
        self::assertStringContainsString('Front brake pads', $card);
        self::assertStringContainsString('In about 5,000 mi, about', $card);
        self::assertStringContainsString('Wiper blades', $card);
        self::assertStringContainsString('No date given', $card);

        $added = $this->browser->post('/scan/' . $token . '/reminders', ['item' => '0']);
        self::assertSame('/scan/' . $token . '/reminders', $added->getHeaderLine('Location'), 'one left to offer');
        $reminders = $this->manualReminders();
        self::assertCount(1, $reminders);
        self::assertSame('Front brake pads', $reminders[0]->title);
        self::assertNull($reminders[0]->dueOn, 'kept as a distance (#82)');
        // 48,120 mi (77,441.633 km, as stored) + 5,000 mi (8,046.720 km).
        self::assertSame('85488.353', $reminders[0]->dueKm);
        self::assertStringContainsString('Added', self::body($this->browser->get('/scan/' . $token . '/reminders')));

        $done = $this->browser->post('/scan/' . $token . '/reminders', ['item' => 'all']);
        self::assertSame('/vehicles/' . $golf->id . '/maintenance', $done->getHeaderLine('Location'), 'back where the save went');
        self::assertCount(2, $this->manualReminders());
        $guessed = $this->manualReminders()[1];
        self::assertSame('2026-11-14', $guessed->dueOn?->format('Y-m-d'), 'no date or distance: in 30 days');
    }

    /**
     * Acceptance 2: an MOT certificate fills an inspection document with its
     * expiry and mileage; the advisories are offered as reminders.
     */
    public function testAnMotCertificateFillsAnInspectionDocument(): void
    {
        $fixture = ScanFixture::load('10-mot-pass');
        [$bytes, $reply] = [$fixture->bytes(), $fixture->reply];
        [$form, $token] = $this->scanToForm($bytes, $reply, 'mot.pdf', 'application/pdf');
        $golf = $this->garage['Golf'];
        $values = Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'));

        $saved = $this->browser->post('/vehicles/' . $golf->id . '/documents/new', $values);
        self::assertSame('/scan/' . $token . '/reminders', $saved->getHeaderLine('Location'));
        $documents = $this->service($this->app, ComplianceDocumentRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $documents);
        self::assertSame(ComplianceType::Inspection, $documents[0]->data->type);
        self::assertSame('2027-02-28', $documents[0]->data->expiryOn?->format('Y-m-d'));
        self::assertNotNull($documents[0]->data->odometerKm);
        $readings = $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $readings, "the certificate's reading joins the mileage log");
        self::assertSame(1, $this->rows('attachments'));

        $card = self::body($this->browser->get('/scan/' . $token . '/reminders'));
        self::assertStringContainsString('Nearside front tyre worn close to legal limit', $card);
        self::assertStringContainsString('Front brake disc worn, pitted or scored', $card);
    }

    /**
     * Acceptance 3: a model that fails still leaves the file on an empty form.
     */
    public function testAFailedReadLeavesTheFileOnAnEmptyForm(): void
    {
        $this->provider->queue(new MockResponse('{"error": "boom"}', ['http_code' => 500]));
        [$page, $path] = $this->land($this->scan($this->invoicePhoto(), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
            'for' => 'maintenance',
        ]));
        $html = self::body($page);
        self::assertStringStartsWith('/vehicles/' . $this->garage['Golf']->id . '/maintenance/new?scan=', (string) end($path));
        self::assertStringContainsString(
            'Couldn’t read this file. It’s attached; fill the form in by hand.',
            html_entity_decode($html),
        );
        self::assertStringNotContainsString('field__from-file', $html);

        $values = Html::formValues(Html::element(Html::document($html), 'form.form'));
        $this->browser->post('/vehicles/' . $this->garage['Golf']->id . '/maintenance/new', [
            'title' => 'Brakes',
            'category' => 'brakes',
        ] + $values);
        self::assertSame(1, $this->rows('maintenance_entries'));
        self::assertSame(1, $this->rows('attachments'), 'scanning never costs the user their photo');
    }

    public function testAnUnreadableFileWithNoFormToGoBackToAsksWhichForm(): void
    {
        $this->provider->queue(ScriptedProvider::answer('not JSON at all'));
        [$page] = $this->land($this->scan($this->invoicePhoto(), 'receipt.jpg', 'image/jpeg'));

        self::assertStringContainsString('Which form?', self::body($page));
        self::assertStringContainsString('Service record', self::body($page));
    }

    public function testAPhotoNeedsAModelThatTakesImages(): void
    {
        $this->scanApp([], null, [Capability::Json]);
        [$page] = $this->land($this->scan($this->invoicePhoto(), 'receipt.jpg', 'image/jpeg', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
            'for' => 'maintenance',
        ]));

        self::assertSame([], $this->provider->requests, 'nothing is sent to a model that cannot see it');
        self::assertStringContainsString('doesn’t take pictures', html_entity_decode(self::body($page)));
        self::assertStringContainsString('receipt.jpg will be attached.', self::body($page));
    }

    public function testAScannedPdfWithoutARendererAsksForAPhoto(): void
    {
        $this->renderer->available = false;
        [$page] = $this->land($this->scan(ScanFiles::scannedPdf(['Invoice', 'Total GBP 10']), 'scan.pdf', 'application/pdf', [
            'vehicle_id' => (string) $this->garage['Golf']->id,
            'for' => 'maintenance',
        ]));

        self::assertSame([], $this->provider->requests);
        self::assertStringContainsString('This PDF is a scan. Take a photo instead, or type it in.', self::body($page));
        self::assertStringContainsString('scan.pdf will be attached.', self::body($page));
    }

    public function testAnotherUsersScanIsNotFoundAndNeverAttached(): void
    {
        [, $token] = $this->scanToForm($this->invoicePhoto(), $this->invoiceReply());
        $member = $this->createMember($this->app, 'partner');
        $partner = $this->browserFor($this->app, 'partner');

        self::assertSame(404, $partner->get('/scan/' . $token)->getStatusCode());
        self::assertSame(404, $partner->get('/scan/' . $token . '/file')->getStatusCode());
        self::assertSame(200, $this->browser->get('/scan/' . $token . '/file')->getStatusCode(), 'the owner sees the thumbnail');

        // Partner's own vehicle, with the owner's token posted.
        $theirs = $this->service($this->app, VehicleService::class)
            ->create($member, new VehicleData(VehicleType::Car, 'Skoda', 'Fabia', FuelType::Petrol));
        $partner->post('/vehicles/' . $theirs->id . '/maintenance/new', [
            'title' => 'Wash',
            'category' => 'other',
            'performed_on' => '2026-10-01',
            'scan' => $token,
        ]);
        self::assertSame(0, $this->rows('attachments'));
        self::assertNotNull($this->pending($token)->storedPath, 'still waiting for its own user');
    }

    public function testPendingUploadsAreDeletedAfter24Hours(): void
    {
        [, $token] = $this->scanToForm($this->invoicePhoto(), $this->invoiceReply());
        $path = $this->uploadDir() . '/' . $this->pending($token)->storedPath;
        $clock = $this->service($this->app, ClockInterface::class);
        self::assertInstanceOf(MutableClock::class, $clock);

        $clock->set(new \DateTimeImmutable('2026-10-16T11:00:00Z'));
        $this->service($this->app, AiHousekeeping::class)->run();
        self::assertFileExists($path, 'kept for 24 hours');

        $clock->set(new \DateTimeImmutable('2026-10-16T12:01:00Z'));
        $this->service($this->app, AiHousekeeping::class)->run();
        self::assertFileDoesNotExist($path);
        self::assertSame(0, $this->rows('pending_uploads'));
        self::assertSame(404, $this->browser->get('/scan/' . $token)->getStatusCode());
    }

    public function testPendingFilesStayOutOfBackups(): void
    {
        [, $token] = $this->scanToForm($this->invoicePhoto(), $this->invoiceReply());
        $all = $this->service($this->app, FileStorage::class)->all();

        self::assertNotContains($this->pending($token)->storedPath, $all);
    }

    public function testRemovingTheFileSavesWithoutIt(): void
    {
        [$form, $token] = $this->scanToForm($this->invoicePhoto(), $this->invoiceReply());
        $values = Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'));
        $path = $this->uploadDir() . '/' . $this->pending($token)->storedPath;

        $this->browser->post('/vehicles/' . $this->garage['Golf']->id . '/maintenance/new', ['scan_remove' => '1'] + $values);

        self::assertSame(1, $this->rows('maintenance_entries'));
        self::assertSame(0, $this->rows('attachments'));
        self::assertFileDoesNotExist($path, 'a removed file is not kept');
    }

    /**
     * A document containing instructions produces only a form: no tools are
     * offered, the instructions are data, nothing saves without *Save*.
     */
    public function testInstructionsInADocumentProduceOnlyAForm(): void
    {
        $fixture = ScanFixture::load('19-injection-invoice');
        [$bytes, $reply] = [$fixture->bytes(), $fixture->reply];
        [$form] = $this->scanToForm($bytes, $reply, 'invoice.pdf', 'application/pdf');

        $request = $this->request(0);
        self::assertArrayNotHasKey('tools', $request, 'no tools to call');
        self::assertStringContainsString('data, not instructions', $this->sentText(0));
        self::assertSame(0, $this->rows('maintenance_entries'));
        self::assertSame('45', Html::formValues(Html::element(Html::document(self::body($form)), 'form.form'))['cost']);
    }

    public function testReadingItAsAnotherKindMapsTheSameReadingAgain(): void
    {
        [, $token] = $this->scanToForm($this->invoicePhoto(), $this->invoiceReply());
        [$page, $path] = $this->land($this->browser->get('/scan/' . $token . '?as=other&vehicle=' . $this->garage['Golf']->id));

        $documents = '/vehicles/' . $this->garage['Golf']->id . '/documents/new';
        self::assertStringStartsWith($documents . '?scan=' . $token . '&as=other', (string) end($path));
        self::assertCount(1, $this->provider->requests, 'no second request');
        $values = Html::formValues(Html::element(Html::document(self::body($page)), 'form.form'));
        self::assertSame('Brightwater Motors Ltd', $values['provider']);
    }

    public function testASwitchedOffModuleOpensNoForm(): void
    {
        $this->switchOff(Feature::Fuel);
        $fixture = ScanFixture::load('08-diesel-receipt-photo');
        [$bytes, $reply] = [$fixture->bytes(), $fixture->reply];
        $this->reply($reply);
        [$page] = $this->land($this->scan($bytes, 'receipt.jpg', 'image/jpeg'));

        self::assertStringContainsString('is switched off, so there is no form for this', self::body($page));
    }

    /**
     * A registration document's details as ticked updates; the file is kept
     * with the purchase paperwork only when ticked.
     */
    public function testARegistrationDocumentUpdatesTheTickedDetails(): void
    {
        $leaf = $this->garage['Leaf'];
        $fixture = ScanFixture::load('16-v5c-text');
        [$bytes, $reply] = [$fixture->bytes(), $fixture->reply];
        [$page, $path] = $this->land((function () use ($reply, $bytes): ResponseInterface {
            $this->reply($reply);

            return $this->scan($bytes, 'v5c.pdf', 'application/pdf');
        })());
        $html = self::body($page);
        self::assertStringContainsString('never reads it', html_entity_decode($html));
        self::assertStringContainsString('Keep the file as a registration document', $html);
        $token = substr((string) preg_replace('#^/scan/([a-f0-9]{32}).*$#', '$1', (string) end($path)), 0, 32);

        $saved = $this->browser->post('/scan/' . $token . '/vehicle?vehicle=' . $leaf->id, [
            'scan' => $token,
            'update' => ['vin', 'first_registered_on'],
        ]);
        self::assertSame('/vehicles/' . $leaf->id, $saved->getHeaderLine('Location'));
        $updated = $this->service($this->app, VehicleRepository::class)->findById($leaf->id);
        self::assertNotNull($updated);
        self::assertSame('SJNFAAZE1U0123456', $updated->data->vin);
        self::assertSame('2020-09-14', $updated->data->firstRegisteredOn?->format('Y-m-d'));
        self::assertSame('EV70 LTR', $updated->data->registration);
        self::assertSame(0, $this->rows('attachments'), 'not kept unless ticked');
        self::assertNull($this->pending($token)->storedPath);
    }

    /**
     * Kept, the V5C goes on a registration document, which the sale pack
     * never offers; it is stored stripped, once, and the pending file goes.
     */
    public function testAKeptRegistrationDocumentIsNeverInTheSalePack(): void
    {
        $golf = $this->garage['Golf'];
        $fixture = ScanFixture::load('15-v5c-photo');
        $this->reply($fixture->reply);
        [, $path] = $this->land($this->scan($fixture->bytes(), 'v5c.jpg', 'image/jpeg'));
        $token = substr((string) preg_replace('#^/scan/([a-f0-9]{32}).*$#', '$1', (string) end($path)), 0, 32);
        $pendingFile = $this->uploadDir() . '/' . $this->pending($token)->storedPath;

        $this->browser->post('/scan/' . $token . '/vehicle?vehicle=' . $golf->id, [
            'scan' => $token,
            'update' => ['vin'],
            'scan_keep' => '1',
        ]);

        $documents = $this->service($this->app, ComplianceDocumentRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $documents);
        self::assertSame(ComplianceType::Registration, $documents[0]->data->type);
        $files = $this->service($this->app, AttachmentRepository::class)
            ->listForOwner($golf->id, AttachmentOwner::Compliance, $documents[0]->id);
        self::assertCount(1, $files);
        self::assertFalse(ExifJpeg::hasExif((string) file_get_contents($this->uploadDir() . '/' . $files[0]->storedPath)));
        self::assertSame([], $this->service($this->app, AttachmentRepository::class)
            ->listForOwner($golf->id, AttachmentOwner::Purchase, $golf->id), 'not with the purchase paperwork');
        self::assertFileDoesNotExist($pendingFile);
        self::assertContains('registration', \Logbook\Service\SalePack\PaperworkKind::NEVER_OFFERED);

        $pack = self::body($this->browser->get('/vehicles/' . $golf->id . '/sale-pack'));
        self::assertStringNotContainsString('v5c.jpg', $pack, 'the sale pack never offers it');
    }

    public function testARestoreClearsScansWaitingForAnEntry(): void
    {
        [, $token] = $this->scanToForm($this->invoicePhoto(), $this->invoiceReply());
        $path = $this->uploadDir() . '/' . $this->pending($token)->storedPath;
        $repository = $this->service($this->app, \Logbook\Repository\BackupRepository::class);

        $data = [];
        foreach (\Logbook\Repository\BackupRepository::TABLES as $table) {
            $data[$table] = $repository->rows($table);
        }
        $repository->replaceAll($data);

        self::assertSame(0, $this->rows('pending_uploads'));
        self::assertFileExists($path, 'the file swap is the service\'s: see BackupTest');
    }

    public function testScanningIsOnlyThereWhenItIsAvailable(): void
    {
        self::assertStringContainsString('Scan a receipt or document', self::body($this->browser->get('/log/new')));
        $form = '/vehicles/' . $this->garage['Golf']->id . '/maintenance/new';
        self::assertStringContainsString('Fill from a file', self::body($this->browser->get($form)));
        $manifest = json_decode(self::body($this->browser->get('/manifest.webmanifest')), true);
        self::assertIsArray($manifest);
        $shortcuts = $manifest['shortcuts'] ?? null;
        self::assertIsArray($shortcuts);
        self::assertContains(['name' => 'Scan', 'url' => '/scan'], $shortcuts);

        $this->switchOff(Feature::AiScan);
        self::assertSame(404, $this->browser->get('/scan')->getStatusCode());
        self::assertStringNotContainsString('Scan a receipt or document', self::body($this->browser->get('/log/new')));
        self::assertStringNotContainsString('Fill from a file', self::body($this->browser->get($form)));
    }

    public function testTheScanPageSaysWhereTheFileGoes(): void
    {
        $page = self::body($this->browser->get('/scan'));
        self::assertStringContainsString(
            'Read by Ollama on the desktop on your network. The file doesn’t leave it.',
            html_entity_decode($page),
        );
        self::assertStringNotContainsString('document reference number', $page);
        self::assertStringContainsString('capture="environment"', $page, 'the camera on a phone');
    }

    public function testWorksBehindASubpath(): void
    {
        $this->scanApp(['APP_BASE_PATH' => '/logbook']);
        $this->reply($this->invoiceReply());
        $response = $this->browser->post('/logbook/scan', ['vehicle_id' => (string) $this->garage['Golf']->id], [
            'file' => $this->upload($this->invoicePhoto(), 'invoice.jpg', 'image/jpeg'),
        ]);
        self::assertStringStartsWith('/logbook/scan/', $response->getHeaderLine('Location'));
        $form = $this->browser->follow($response);
        $create = '/logbook/vehicles/' . $this->garage['Golf']->id . '/maintenance/new?scan=';
        self::assertStringStartsWith($create, $form->getHeaderLine('Location'));
        $html = self::body($this->browser->follow($form));
        self::assertMatchesRegularExpression('#src="/logbook/scan/[a-f0-9]{32}/file"#', $html, 'the thumbnail, subpath and all');
    }

    /**
     * @param array<string, mixed> $reply
     * @return array{ResponseInterface, string} the prefilled form and the scan's token
     */
    private function scanToForm(string $bytes, array $reply, string $name = 'invoice.jpg', string $mime = 'image/jpeg'): array
    {
        $this->reply($reply);
        [$page, $path] = $this->land($this->scan($bytes, $name, $mime));
        self::assertSame(200, $page->getStatusCode());
        $last = (string) end($path);
        self::assertMatchesRegularExpression('#[?&]scan=([a-f0-9]{32})#', $last, implode(' → ', $path));
        $token = (string) preg_replace('#^.*[?&]scan=([a-f0-9]{32}).*$#', '$1', $last);

        return [$page, $token];
    }

    private function invoicePhoto(): string
    {
        return ExifJpeg::make(40, 20, 6);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceReply(): array
    {
        $reply = ScanFixture::load('01-service-invoice')->reply;
        $lines = [];
        foreach (is_array($reply['lines'] ?? null) ? $reply['lines'] : [] as $key => $list) {
            $lines[(string) $key] = $list;
        }
        $lines['recommendations'] = [
            ['text' => 'Front brake pads', 'distance' => '5,000', 'distance_unit' => 'miles', 'date' => null],
            ['text' => 'Wiper blades', 'distance' => null, 'distance_unit' => null, 'date' => null],
        ];

        return ['lines' => $lines] + $reply;
    }

    private function pending(string $token): \Logbook\Domain\Ai\Scan\ScanUpload
    {
        $upload = $this->service($this->app, PendingUploadRepository::class)->find($this->owner->id, $token);
        self::assertNotNull($upload);

        return $upload;
    }

    private function rows(string $table): int
    {
        $count = $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM ' . $table);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<\Logbook\Domain\Reminder\Reminder>
     */
    private function manualReminders(): array
    {
        $all = $this->service($this->app, ReminderRepository::class)->listForVehicles(array_map(
            static fn (Vehicle $v): int => $v->id,
            array_values($this->garage),
        ));

        return array_values(array_filter($all, static fn ($r): bool => $r->source === ReminderSource::Manual));
    }

    /**
     * @param array<string, string> $readings local time → reading in miles
     */
    private function readings(Vehicle $vehicle, array $readings): void
    {
        foreach ($readings as $at => $reading) {
            $this->browser->post('/vehicles/' . $vehicle->id . '/odometer/new', ['recorded_at' => $at, 'reading' => $reading]);
        }
    }

    private function switchOff(Feature $off): void
    {
        $toggles = $this->service($this->app, FeatureToggles::class);
        $states = $toggles->all();
        $toggles->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => $f !== $off && $states[$f->value],
        )));
    }
}
