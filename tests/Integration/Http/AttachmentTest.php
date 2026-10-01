<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Support\View\View;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Attachments on fill-ups, maintenance and compliance documents: one upload
 * path (the Phase 1 photo handler, generalised), stored outside the web
 * root, served only to the signed-in owner, validated by content and size.
 */
final class AttachmentTest extends AppTestCase
{
    /** A valid 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** A minimal PDF. */
    private const string PDF = "%PDF-1.4\n"
        . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n"
        . "trailer<</Root 1 0 R>>\n%%EOF\n";

    private const array POLICY = [
        'type' => 'insurance',
        'title' => '',
        'provider' => 'Acme Insurance',
        'reference' => '',
        'start_on' => '2026-01-01',
        'expiry_on' => '2026-12-31',
        'cost' => '',
        'notes' => '',
    ];

    private const array SERVICE = [
        'performed_on' => '2026-09-01',
        'odometer' => '',
        'category' => 'service',
        'title' => 'Annual service',
        'cost' => '150',
        'vendor' => '',
        'description' => '',
        'schedule' => '',
    ];

    private const array FILL = [
        'filled_at' => '2026-07-01T09:15',
        'odometer' => '10000',
        'fuel' => 'petrol',
        'volume' => '40',
        'price' => '1.5',
        'total' => '',
    ];

    private const array PARKING = ['category' => 'parking', 'spent_on' => '2026-09-02', 'amount' => '4.5', 'note' => ''];

    private const array READING = ['recorded_at' => '2026-09-03T08:00', 'reading' => '11000', 'note' => ''];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        array_map(static fn (string $file) => is_file($file) && unlink($file), $this->tempFiles);
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testAPdfOnADocumentIsStoredPrivatelyAndServedOnlyToTheOwner(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $created = $browser->post(
            '/vehicles/' . $golf->id . '/documents/new',
            self::POLICY,
            ['attachments' => [$this->upload(self::PDF, '../../Policy schedule "2026".pdf', 'application/octet-stream')]],
        );
        self::assertSame(303, $created->getStatusCode());

        $attachment = $this->onlyAttachment($app, $golf);
        $document = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame($document->id, $attachment->ownerId);
        self::assertSame('application/pdf', $attachment->mime, 'detected from the content');
        self::assertSame('Policy schedule "2026".pdf', $attachment->filename, 'no directories from the client name');
        self::assertSame(strlen(self::PDF), $attachment->size);
        self::assertMatchesRegularExpression('~^attachments/[a-f0-9]{32}\.pdf$~', $attachment->storedPath, 'a random name');
        self::assertFileExists($this->uploadDir() . '/' . $attachment->storedPath);
        self::assertFileDoesNotExist(Kernel::rootDir() . '/public/' . $attachment->storedPath);

        $list = self::body($browser->get('/vehicles/' . $golf->id . '/documents'));
        $url = '/vehicles/' . $golf->id . '/attachments/' . $attachment->id;
        self::assertStringContainsString('href="' . $url . '?v=' . $attachment->version() . '"', $list);

        $file = $browser->get($url);
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('application/pdf', $file->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $file->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', $file->getHeaderLine('Content-Security-Policy'));
        self::assertStringStartsWith('private', $file->getHeaderLine('Cache-Control'));
        self::assertSame(
            'attachment; filename="Policy schedule _2026_.pdf"; filename*=UTF-8\'\'Policy%20schedule%20%222026%22.pdf',
            $file->getHeaderLine('Content-Disposition'),
            'a PDF downloads under its original name',
        );
        self::assertSame(self::PDF, self::body($file));

        $anonymous = (new TestBrowser($app))->get($url);
        self::assertSame(303, $anonymous->getStatusCode());
        self::assertStringStartsWith('/login', $anonymous->getHeaderLine('Location'));

        $bike = $this->vehicle($app);
        self::assertSame(404, $browser->get('/vehicles/' . $bike->id . '/attachments/' . $attachment->id)->getStatusCode());
    }

    public function testImagesShowInlineAndAddMoreFilesOnEdit(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/maintenance';

        $browser->post($base . '/new', self::SERVICE, ['attachments' => [$this->upload(self::PDF, 'invoice.pdf')]]);
        $entry = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id)[0];

        $photo = $this->upload(base64_decode(self::PNG), 'odometer.png');
        $browser->post($base . '/' . $entry->id . '/edit', self::SERVICE, ['attachments' => [$photo]]);
        $attachments = $this->attachments($app, $golf);
        self::assertCount(2, $attachments);
        self::assertSame('image/png', $attachments[1]->mime);

        $edit = self::body($browser->get($base . '/' . $entry->id . '/edit'));
        self::assertStringContainsString('invoice.pdf', $edit);
        self::assertStringContainsString('odometer.png', $edit);
        self::assertStringContainsString('Attach more files', $edit);

        $image = $browser->get('/vehicles/' . $golf->id . '/attachments/' . $attachments[1]->id);
        self::assertSame('image/png', $image->getHeaderLine('Content-Type'));
        self::assertSame('inline', $image->getHeaderLine('Content-Disposition'));

        $cached = $browser->get('/vehicles/' . $golf->id . '/attachments/' . $attachments[1]->id, [
            'If-None-Match' => $image->getHeaderLine('ETag'),
        ]);
        self::assertSame(304, $cached->getStatusCode());

        self::assertStringContainsString('2 files', self::body($browser->get($base)), 'the history row shows a paperclip');
    }

    public function testFillUpsTakeAReceipt(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $base = '/vehicles/' . $golf->id . '/fuel';
        $fill = ['filled_at' => '2026-07-01T09:15', 'odometer' => '10000', 'fuel' => 'petrol', 'volume' => '40'];
        $receipt = $this->upload(base64_decode(self::PNG), 'receipt.png');
        $created = $browser->post($base . '/new', $fill + ['price' => '1.5', 'total' => ''], ['attachments' => [$receipt]]);
        self::assertSame(303, $created->getStatusCode());

        $entry = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id)[0];
        $attachment = $this->onlyAttachment($app, $golf);
        self::assertSame($entry->id, $attachment->ownerId);
        self::assertStringContainsString('receipt.png', self::body($browser->get($base . '/' . $entry->id . '/edit')));

        // Deleting the fill-up deletes its receipt.
        $browser->post($base . '/' . $entry->id . '/delete');
        self::assertSame([], $this->attachments($app, $golf));
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $attachment->storedPath);
    }

    public function testRejectsFilesOfTheWrongTypeWhateverTheirName(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $script = $this->upload('<?php echo "hi";', 'policy.pdf', 'application/pdf');
        $response = $browser->post('/vehicles/' . $golf->id . '/documents/new', self::POLICY, ['attachments' => [$script]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('policy.pdf: not a PDF, JPEG, PNG or WebP file', self::body($response));
        self::assertStringContainsString('value="Acme Insurance"', self::body($response), 'the rest of the form is kept');
        $documents = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($golf->id);
        self::assertSame([], $documents, 'nothing is saved');
        self::assertSame([], $this->attachments($app, $golf));
        self::assertDirectoryDoesNotExist($this->uploadDir() . '/attachments');
    }

    public function testRejectsFilesOverTheSizeLimit(): void
    {
        $app = $this->createApp(['MAX_UPLOAD_MB' => '1']);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $big = self::PDF . str_repeat('%', 1024 * 1024);
        $file = $this->upload($big, 'big.pdf');
        $response = $browser->post('/vehicles/' . $golf->id . '/maintenance/new', self::SERVICE, ['attachments' => [$file]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('big.pdf: larger than 1 MB', self::body($response));
        self::assertSame([], $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id));
    }

    public function testDeletingAnAttachmentAnEntryOrTheVehicleDeletesTheFiles(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $documents = '/vehicles/' . $golf->id . '/documents';

        $browser->post($documents . '/new', self::POLICY, ['attachments' => [$this->upload(self::PDF, 'a.pdf')]]);
        $document = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($golf->id)[0];
        $editPath = $documents . '/' . $document->id . '/edit';
        $browser->post($editPath, self::POLICY, ['attachments' => [$this->upload(self::PDF, 'b.pdf')]]);
        [$first, $second] = $this->attachments($app, $golf);

        // One attachment, with confirmation; back to the document.
        $confirm = $browser->get('/vehicles/' . $golf->id . '/attachments/' . $first->id . '/delete');
        self::assertStringContainsString('The file “a.pdf” will be deleted permanently.', self::body($confirm));
        $deleted = $browser->post('/vehicles/' . $golf->id . '/attachments/' . $first->id . '/delete');
        self::assertSame($documents . '/' . $document->id . '/edit', $deleted->getHeaderLine('Location'));
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $first->storedPath);
        self::assertCount(1, $this->attachments($app, $golf));
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/attachments/' . $first->id)->getStatusCode());

        // Editing the document keeps its attachment (same id).
        $browser->post($documents . '/' . $document->id . '/edit', ['provider' => 'Renamed'] + self::POLICY);
        self::assertCount(1, $this->attachments($app, $golf));

        // The document, with its file.
        $browser->post($documents . '/' . $document->id . '/delete');
        self::assertSame([], $this->attachments($app, $golf));
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $second->storedPath);

        // The vehicle, with every file on it.
        $invoice = $this->upload(self::PDF, 'c.pdf');
        $browser->post('/vehicles/' . $golf->id . '/maintenance/new', self::SERVICE, ['attachments' => [$invoice]]);
        $third = $this->onlyAttachment($app, $golf);
        $browser->post('/vehicles/' . $golf->id . '/delete');
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $third->storedPath);
    }

    public function testEveryEntryTakesSeveralFilesAtOnceAsAPageAndInTheModal(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id;
        $forms = [
            'fuel' => ['/fuel/new', self::FILL],
            'maintenance' => ['/maintenance/new', ['odometer' => '10500'] + self::SERVICE],
            'compliance' => ['/documents/new', self::POLICY],
            'expense' => ['/expenses/new', self::PARKING],
            'odometer' => ['/odometer/new', self::READING],
        ];

        foreach ($forms as $owner => [$path, $fields]) {
            $form = self::body($browser->get($base . $path));
            self::assertStringContainsString('enctype="multipart/form-data"', $form, $owner);
            self::assertStringContainsString('name="attachments[]" type="file" multiple', $form, $owner);
            self::assertStringContainsString('Up to 10 files, each up to 10 MB.', $form, $owner);

            // As a page, then again from the modal (fetch with FormData).
            foreach ([[], [View::MODAL_HEADER => '1']] as $headers) {
                $response = $browser->post($base . $path, $fields, ['attachments' => $this->three()], headers: $headers);
                $expected = $headers === [] ? 303 : 204;
                self::assertSame($expected, $response->getStatusCode(), $owner . ': ' . self::body($response));
            }
        }

        $byOwner = [];
        foreach ($this->attachments($app, $golf) as $attachment) {
            $byOwner[$attachment->ownerType->value][$attachment->ownerId][] = $attachment->filename;
        }
        self::assertSame(['fuel', 'maintenance', 'compliance', 'expense', 'odometer'], array_keys($byOwner));
        foreach ($byOwner as $owner => $entries) {
            self::assertCount(2, $entries, $owner . ': two saves');
            foreach ($entries as $names) {
                self::assertSame(['receipt.pdf', 'photo.png', 'invoice.pdf'], $names, $owner);
            }
        }

        // Each list shows its rows' paperclips.
        foreach (['/fuel', '/maintenance', '/expenses', '/odometer'] as $list) {
            self::assertStringContainsString('3 files', self::body($browser->get($base . $list)), $list);
        }
        $mileage = self::body($browser->get($base . '/odometer'));
        // Two manual readings, plus the fill-up and service readings showing their entries' files.
        self::assertSame(6, substr_count($mileage, 'title="3 files"'));
    }

    public function testElevenFilesAreRefusedAndOneBadFileStoresNothing(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $path = '/vehicles/' . $golf->id . '/maintenance/new';

        $eleven = [];
        for ($i = 1; $i <= 11; $i++) {
            $eleven[] = $this->upload(self::PDF, 'page-' . $i . '.pdf');
        }
        $refused = $browser->post($path, self::SERVICE, ['attachments' => $eleven]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Choose up to 10 files at a time.', self::body($refused));
        self::assertStringContainsString('value="Annual service"', self::body($refused), 'the typed values are kept');

        $ten = array_slice($eleven, 0, 10);
        self::assertSame(303, $browser->post($path, self::SERVICE, ['attachments' => $ten])->getStatusCode(), 'ten are fine');
        self::assertCount(10, $this->attachments($app, $golf));

        $bad = [
            $this->upload(self::PDF, 'a.pdf'),
            $this->upload('not really a picture', 'receipt.heic', 'image/heic'),
            $this->upload(base64_decode(self::PNG), 'c.png'),
        ];
        $response = $browser->post('/vehicles/' . $golf->id . '/expenses/new', ['note' => 'Car park'] + self::PARKING, [
            'attachments' => $bad,
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('receipt.heic: not a PDF, JPEG, PNG or WebP file', self::body($response));
        self::assertStringContainsString('value="Car park"', self::body($response));
        self::assertCount(10, $this->attachments($app, $golf), 'no rows');
        self::assertSame([], $this->service($app, ExpenseEntryRepository::class)->listForVehicle($golf->id), 'no expense');
        self::assertCount(10, FileStorage::storedFilesIn($this->uploadDir()), 'no files on disk');
    }

    public function testAFailedSaveDeletesTheFilesItWrote(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $check = static fn (UploadedFile $file): FileUpload => FileUpload::check($file, 1024 * 1024, UploadKind::Document);
        $files = new PendingUploads(array_map(
            static fn (UploadedFile $file): PendingUpload => new PendingUpload($file, $check($file)),
            $this->three(),
        ));

        $caught = null;
        try {
            $this->service($app, AttachmentService::class)->saveWithFiles($files, static function (array $stored): int {
                self::assertCount(3, $stored, 'written first');
                throw new RuntimeException('the entry could not be saved');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        self::assertInstanceOf(RuntimeException::class, $caught, 'the failure reaches the caller');
        self::assertSame('the entry could not be saved', $caught->getMessage());
        self::assertSame([], FileStorage::storedFilesIn($this->uploadDir()), 'orphans are deleted');
        self::assertSame([], $this->attachments($app, $golf));
    }

    public function testExpenseAndReadingFilesAreTheOwnersOnlyAndGoWithTheirEntry(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id;

        $fine = ['category' => 'fines', 'amount' => '60'] + self::PARKING;
        $browser->post($base . '/expenses/new', $fine, ['attachments' => [$this->upload(self::PDF, 'penalty.pdf')]]);
        $browser->post($base . '/odometer/new', self::READING, [
            'attachments' => [$this->upload(base64_decode(self::PNG), 'dashboard.png')],
        ]);
        [$penalty, $dashboard] = $this->attachments($app, $golf);
        self::assertSame(AttachmentOwner::Expense, $penalty->ownerType);
        self::assertSame(AttachmentOwner::Odometer, $dashboard->ownerType);

        $expense = $this->service($app, ExpenseEntryRepository::class)->listForVehicle($golf->id)[0];
        $reading = $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id)[0];
        self::assertStringContainsString('penalty.pdf', self::body($browser->get($base . '/expenses/' . $expense->id . '/edit')));
        $readingForm = self::body($browser->get($base . '/odometer/' . $reading->id . '/edit'));
        self::assertStringContainsString('dashboard.png', $readingForm);

        foreach ([$penalty, $dashboard] as $attachment) {
            $url = $base . '/attachments/' . $attachment->id;
            self::assertSame(200, $browser->get($url)->getStatusCode());
            self::assertStringStartsWith('/login', (new TestBrowser($app))->get($url)->getHeaderLine('Location'));
        }
        $bike = $this->vehicle($app);
        $elsewhere = '/vehicles/' . $bike->id . '/attachments/' . $penalty->id;
        self::assertSame(404, $browser->get($elsewhere)->getStatusCode());

        // Deleting one from the edit form returns to that entry.
        $deleted = $browser->post($base . '/attachments/' . $penalty->id . '/delete');
        self::assertSame($base . '/expenses/' . $expense->id . '/edit', $deleted->getHeaderLine('Location'));

        $browser->post($base . '/expenses/' . $expense->id . '/edit', $fine, [
            'attachments' => [$this->upload(self::PDF, 'appeal.pdf')],
        ]);
        $appeal = $this->attachments($app, $golf)[1];
        $browser->post($base . '/expenses/' . $expense->id . '/delete');
        $browser->post($base . '/odometer/' . $reading->id . '/delete');
        self::assertSame([], $this->attachments($app, $golf));
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $appeal->storedPath);
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $dashboard->storedPath);
    }

    public function testDerivedReadingsHaveNoAttachmentInput(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id;
        $browser->post($base . '/fuel/new', self::FILL);
        $reading = $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id)[0];

        $edit = $browser->get($base . '/odometer/' . $reading->id . '/edit');
        self::assertSame(303, $edit->getStatusCode(), 'a fill-up\'s reading is edited on the fill-up, with its files');
        self::assertStringContainsString('/fuel/', $edit->getHeaderLine('Location'));
    }

    public function testTheFileLimitNeverExceedsPhpsMaxFileUploads(): void
    {
        self::assertSame(10, AttachmentService::fileLimit(20));
        self::assertSame(10, AttachmentService::fileLimit(10));
        self::assertSame(4, AttachmentService::fileLimit(4), 'PHP would drop the fifth silently');
        self::assertSame(0, AttachmentService::fileLimit(0));
    }

    public function testAPhotoIsStoredUprightWithoutItsGpsAndThePdfAsUploaded(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $photo = $this->upload(ExifJpeg::make(40, 20, 6), 'receipt.jpg', 'image/jpeg');
        $browser->post('/vehicles/' . $golf->id . '/expenses/new', ['note' => 'Car park'] + self::PARKING, [
            'attachments' => [$photo, $this->upload(self::PDF, 'ticket.pdf')],
        ]);

        [$jpeg, $pdf] = $this->attachments($app, $golf);
        $stored = (string) file_get_contents($this->uploadDir() . '/' . $jpeg->storedPath);
        self::assertFalse(ExifJpeg::hasExif($stored), 'no EXIF, so no GPS, is stored');
        self::assertSame([20, 40], array_slice((array) getimagesizefromstring($stored), 0, 2), 'turned upright');
        self::assertSame(strlen($stored), $jpeg->size, 'the size is the stored file\'s');
        self::assertSame(self::PDF, (string) file_get_contents($this->uploadDir() . '/' . $pdf->storedPath));
    }

    /**
     * @return list<UploadedFile> a PDF, a PNG and another PDF
     */
    private function three(): array
    {
        return [
            $this->upload(self::PDF, 'receipt.pdf'),
            $this->upload(base64_decode(self::PNG), 'photo.png'),
            $this->upload(self::PDF, 'invoice.pdf'),
        ];
    }

    private function upload(string $contents, string $name, string $type = 'application/pdf'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, $type, strlen($contents), UPLOAD_ERR_OK);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function vehicle(App $app): Vehicle
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $this->service($app, VehicleService::class)
            ->create($owner, new VehicleData(VehicleType::Car, 'Volkswagen', 'Golf', FuelType::Petrol));
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Attachment>
     */
    private function attachments(App $app, Vehicle $vehicle): array
    {
        return $this->service($app, AttachmentRepository::class)->listForVehicle($vehicle->id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function onlyAttachment(App $app, Vehicle $vehicle): Attachment
    {
        $attachments = $this->attachments($app, $vehicle);
        self::assertCount(1, $attachments);

        return $attachments[0];
    }
}
