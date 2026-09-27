<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
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
            ['attachment' => $this->upload(self::PDF, '../../Policy schedule "2026".pdf', 'application/octet-stream')],
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

        $browser->post($base . '/new', self::SERVICE, ['attachment' => $this->upload(self::PDF, 'invoice.pdf')]);
        $entry = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id)[0];

        $photo = $this->upload(base64_decode(self::PNG), 'odometer.png');
        $browser->post($base . '/' . $entry->id . '/edit', self::SERVICE, ['attachment' => $photo]);
        $attachments = $this->attachments($app, $golf);
        self::assertCount(2, $attachments);
        self::assertSame('image/png', $attachments[1]->mime);

        $edit = self::body($browser->get($base . '/' . $entry->id . '/edit'));
        self::assertStringContainsString('invoice.pdf', $edit);
        self::assertStringContainsString('odometer.png', $edit);
        self::assertStringContainsString('Attach another file', $edit);

        $image = $browser->get('/vehicles/' . $golf->id . '/attachments/' . $attachments[1]->id);
        self::assertSame('image/png', $image->getHeaderLine('Content-Type'));
        self::assertSame('inline', $image->getHeaderLine('Content-Disposition'));

        $cached = $browser->get('/vehicles/' . $golf->id . '/attachments/' . $attachments[1]->id, [
            'If-None-Match' => $image->getHeaderLine('ETag'),
        ]);
        self::assertSame(304, $cached->getStatusCode());

        self::assertStringContainsString('2 attachments', self::body($browser->get($base)), 'the history row shows a paperclip');
    }

    public function testFillUpsTakeAReceipt(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $base = '/vehicles/' . $golf->id . '/fuel';
        $fill = ['filled_at' => '2026-07-01T09:15', 'odometer' => '10000', 'fuel' => 'petrol', 'volume' => '40'];
        $receipt = $this->upload(base64_decode(self::PNG), 'receipt.png');
        $created = $browser->post($base . '/new', $fill + ['price' => '1.5', 'total' => ''], ['attachment' => $receipt]);
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
        $response = $browser->post('/vehicles/' . $golf->id . '/documents/new', self::POLICY, ['attachment' => $script]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Choose a PDF, or a JPEG, PNG or WebP image.', self::body($response));
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
        $response = $browser->post('/vehicles/' . $golf->id . '/maintenance/new', self::SERVICE, ['attachment' => $file]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('The file is too large (maximum 1 MB).', self::body($response));
        self::assertSame([], $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id));
    }

    public function testDeletingAnAttachmentAnEntryOrTheVehicleDeletesTheFiles(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $documents = '/vehicles/' . $golf->id . '/documents';

        $browser->post($documents . '/new', self::POLICY, ['attachment' => $this->upload(self::PDF, 'a.pdf')]);
        $document = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($golf->id)[0];
        $editPath = $documents . '/' . $document->id . '/edit';
        $browser->post($editPath, self::POLICY, ['attachment' => $this->upload(self::PDF, 'b.pdf')]);
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
        $browser->post('/vehicles/' . $golf->id . '/maintenance/new', self::SERVICE, ['attachment' => $invoice]);
        $third = $this->onlyAttachment($app, $golf);
        $browser->post('/vehicles/' . $golf->id . '/delete');
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $third->storedPath);
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
