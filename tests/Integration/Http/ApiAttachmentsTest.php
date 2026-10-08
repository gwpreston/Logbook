<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\ExifJpeg;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Attachments over the API (Phase 39.3, spec.md §7.20 *Attachments*, #286,
 * #300): list, upload one file per request, download through the pages'
 * handler and delete, with the pages' checks, limits and access rules, and
 * the vehicle photo on its own path. ApiClient checks every response
 * against the OpenAPI description, so these are contract tests too.
 */
final class ApiAttachmentsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string PDF = "%PDF-1.4\n"
        . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n"
        . "trailer<</Root 1 0 R>>\n%%EOF\n";

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_TRIPS' => 'true', 'MAX_UPLOAD_MB' => '1']);
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $this->base = '/vehicles/' . $this->golf->id;
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testAFileIsAddedListedDownloadedAndDeleted(): void
    {
        $fill = $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40000', '40', '60');
        $path = $this->base . '/fuel/' . $fill->id . '/attachments';
        self::assertSame([], ApiClient::json($this->api->get($path))->get('items'));

        $created = $this->api->upload($path, self::PDF, '../receipt.pdf');
        self::assertSame(201, $created->getStatusCode());
        $attachment = ApiClient::json($created);
        self::assertSame('receipt.pdf', $attachment->get('filename'), 'the name is sanitised');
        self::assertSame('application/pdf', $attachment->get('content_type'));
        self::assertSame(['type' => 'fuel', 'id' => $fill->id], $attachment->get('owner'));
        self::assertSame($this->owner->id, $attachment->get('uploaded_by'));
        $id = $attachment->int('id');
        self::assertSame('/attachments/' . $id, $attachment->get('links', 'download'));

        self::assertSame(1, ApiClient::json($this->api->get($path))->doc('items')->count());

        $download = $this->api->get('/attachments/' . $id);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame(self::PDF, (string) $download->getBody());
        self::assertStringContainsString('attachment', $download->getHeaderLine('Content-Disposition'));

        // The pages see it too: one store.
        $stored = $this->service($this->app, AttachmentRepository::class)->find($this->golf->id, $id);
        self::assertNotNull($stored);
        self::assertFileExists($this->uploadDir() . '/' . $stored->storedPath);

        self::assertSame(204, $this->api->delete('/attachments/' . $id)->getStatusCode());
        self::assertSame(404, $this->api->get('/attachments/' . $id)->getStatusCode());
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $stored->storedPath);
    }

    public function testTheFileIsCheckedAsTheFormsCheckIt(): void
    {
        $fill = $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40000', '40', '60');
        $path = $this->base . '/fuel/' . $fill->id . '/attachments';

        $text = $this->api->upload($path, "just text\n", 'receipt.pdf');
        self::assertSame(422, $text->getStatusCode());
        self::assertSame('upload.file.not_a_document', ApiClient::json($text)->get('errors', 'file', 'key'));

        $big = $this->api->upload($path, self::PDF . str_repeat('x', 1024 * 1024), 'big.pdf');
        self::assertSame(422, $big->getStatusCode());
        self::assertSame('upload.file.too_large', ApiClient::json($big)->get('errors', 'file', 'key'));

        $none = $this->api->upload($path, self::PDF, 'receipt.pdf', field: 'attachments');
        self::assertSame(422, $none->getStatusCode());
        self::assertSame('validation.required', ApiClient::json($none)->get('errors', 'file', 'key'));

        // A body over post_max_size reaches the app with no file and no fields.
        $dropped = $this->api->send('POST', $path, null, [
            'Content-Type' => 'multipart/form-data; boundary=x',
            'Content-Length' => '99999999',
        ]);
        self::assertSame('upload.too_large', ApiClient::json($dropped)->get('errors', 'file', 'key'));

        self::assertSame([], $this->service($this->app, AttachmentRepository::class)->listForVehicle($this->golf->id));
    }

    public function testAnImageIsStoredUprightAndStrippedButAnIncidentPhotoAsTaken(): void
    {
        $fill = $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40000', '40', '60');
        $photo = ExifJpeg::make(40, 20, 6);
        $fuelFiles = $this->base . '/fuel/' . $fill->id . '/attachments';
        $receipt = ApiClient::json($this->api->upload($fuelFiles, $photo, 'r.jpg', 'image/jpeg'));
        $stored = (string) $this->api->get('/attachments/' . $receipt->int('id'))->getBody();
        self::assertFalse(ExifJpeg::hasExif($stored));
        self::assertSame([20, 40], array_slice((array) getimagesizefromstring($stored), 0, 2));

        $incident = ApiClient::json($this->api->post($this->base . '/incidents', [
            'occurred_on' => '2026-09-14',
            'type' => 'parked_damage',
            'fault' => 'not_at_fault',
            'damage_areas' => ['rear'],
        ]))->int('entry', 'id');
        $evidence = ApiClient::json(
            $this->api->upload($this->base . '/incidents/' . $incident . '/attachments', $photo, 'dent.jpg', 'image/jpeg'),
        );
        $original = (string) $this->api->get('/attachments/' . $evidence->int('id'))->getBody();
        self::assertTrue(ExifJpeg::hasExif($original), 'the owner gets the photo as taken');

        // A View share without ViewIncidentDetails gets an upright copy without its metadata (#104).
        $viewer = $this->share('viewer', ShareLevel::View);
        $copy = $viewer->get('/attachments/' . $evidence->int('id'));
        self::assertSame(200, $copy->getStatusCode());
        self::assertFalse(ExifJpeg::hasExif((string) $copy->getBody()));
    }

    public function testWhoMayListAddAndDelete(): void
    {
        $fill = $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40000', '40', '60');
        $path = $this->base . '/fuel/' . $fill->id . '/attachments';
        $ownerFile = ApiClient::json($this->api->upload($path, self::PDF, 'owner.pdf'))->int('id');

        // Log: lists, but adds to and deletes only from its own entries.
        $driver = $this->share('driver', ShareLevel::Log);
        self::assertSame(200, $driver->get($path)->getStatusCode());
        $refused = $driver->upload($path, self::PDF, 'mine.pdf');
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('forbidden', ApiClient::json($refused)->get('code'));
        self::assertSame(403, $driver->delete('/attachments/' . $ownerFile)->getStatusCode());
        $own = ApiClient::json($driver->post($this->base . '/odometer', ['odometer' => '41000', 'distance_unit' => 'km']));
        $ownPath = $this->base . '/odometer/' . $own->int('entry', 'id') . '/attachments';
        $mine = $driver->upload($ownPath, self::PDF, 'mine.pdf');
        self::assertSame(201, $mine->getStatusCode());
        self::assertSame(204, $driver->delete('/attachments/' . ApiClient::json($mine)->int('id'))->getStatusCode());

        // View: reads and downloads, never writes.
        $viewer = $this->share('viewer', ShareLevel::View);
        self::assertSame(200, $viewer->get('/attachments/' . $ownerFile)->getStatusCode());
        self::assertSame(403, $viewer->upload($path, self::PDF, 'x.pdf')->getStatusCode());
        self::assertSame(403, $viewer->delete('/attachments/' . $ownerFile)->getStatusCode());

        // Manage: anyone's.
        $manager = $this->share('manager', ShareLevel::Manage);
        self::assertSame(201, $manager->upload($path, self::PDF, 'm.pdf')->getStatusCode());

        // A stranger learns nothing; a read key writes nothing.
        $stranger = $this->api($this->app, $this->apiKey($this->app, $this->createMember($this->app, 'stranger')));
        self::assertSame(404, $stranger->get($path)->getStatusCode());
        self::assertSame(404, $stranger->get('/attachments/' . $ownerFile)->getStatusCode());
        self::assertSame(404, $stranger->delete('/attachments/' . $ownerFile)->getStatusCode());
        $reader = $this->api($this->app, $this->apiKey($this->app, $this->owner, ApiScope::Read));
        self::assertSame('insufficient_scope', ApiClient::json($reader->upload($path, self::PDF, 'r.pdf'))->get('code'));
        self::assertSame(403, $reader->delete('/attachments/' . $ownerFile)->getStatusCode());
        self::assertSame(200, $reader->get('/attachments/' . $ownerFile)->getStatusCode());
    }

    public function testCostFilesFollowCanSeeCosts(): void
    {
        $expense = $this->expense($this->app, $this->golf, '2026-09-02', '4.50');
        $path = $this->base . '/expenses/' . $expense->id . '/attachments';
        self::assertSame(201, $this->api->upload($path, self::PDF, 'ticket.pdf')->getStatusCode());

        $withCosts = $this->share('costs', ShareLevel::View, true);
        self::assertSame(1, ApiClient::json($withCosts->get($path))->doc('items')->count());
        $without = $this->share('nocosts', ShareLevel::View, false);
        self::assertSame(403, $without->get($path)->getStatusCode());
        // Not by id either, on the API or the page (#303); a file of one's own is.
        $file = ApiClient::json($this->api->get($path))->int('items', 0, 'id');
        self::assertSame(404, $without->get('/attachments/' . $file)->getStatusCode());
        self::assertSame(404, $without->delete('/attachments/' . $file)->getStatusCode());
        $page = $this->browserFor($this->app, 'nocosts');
        self::assertSame(404, $page->get($this->base . '/attachments/' . $file)->getStatusCode());
        self::assertSame(200, $withCosts->get('/attachments/' . $file)->getStatusCode());
        $logger = $this->share('logger', ShareLevel::Log, false);
        $mine = ApiClient::json($logger->post($this->base . '/expenses', ['category' => 'parking', 'amount' => '2']))
            ->int('entry', 'id');
        $own = ApiClient::json($logger->upload($this->base . '/expenses/' . $mine . '/attachments', self::PDF, 'mine.pdf'));
        self::assertSame(200, $logger->get('/attachments/' . $own->int('id'))->getStatusCode());
    }

    public function testATripsFilesAreOnlyForThoseWhoSeeTheTrip(): void
    {
        $driver = $this->share('driver', ShareLevel::Log);
        $trip = ApiClient::json($driver->post($this->base . '/trips', [
            'travelled_on' => '2026-09-29',
            'from' => 'Ballymena',
            'to' => 'Belfast',
            'distance_km' => '45.2',
            'purpose' => 'Client visit',
        ]))->int('entry', 'id');
        $path = $this->base . '/trips/' . $trip . '/attachments';
        $file = ApiClient::json($driver->upload($path, self::PDF, 'parking.pdf'))->int('id');

        $other = $this->share('other', ShareLevel::Log);
        self::assertSame(404, $other->get($path)->getStatusCode());
        self::assertSame(404, $other->get('/attachments/' . $file)->getStatusCode());
        self::assertSame(200, $this->api->get('/attachments/' . $file)->getStatusCode(), 'the owner sees everyone\'s trips');
    }

    public function testPaperworkNeedsItsDateAndAReadingAnotherEntryWroteTakesNone(): void
    {
        $undated = $this->api->upload($this->base . '/purchase/attachments', self::PDF, 'invoice.pdf');
        self::assertSame(422, $undated->getStatusCode());
        self::assertSame('vehicle.error.purchase_files_need_date', ApiClient::json($undated)->get('errors', 'file', 'key'));

        $this->api->patch($this->base, ['purchase_date' => '2024-05-01']);
        $paperwork = $this->base . '/purchase/attachments';
        self::assertSame(201, $this->api->upload($paperwork, self::PDF, 'invoice.pdf')->getStatusCode());
        $files = ApiClient::json($this->api->get($paperwork));
        self::assertSame(1, $files->doc('items')->count());
        self::assertSame(['type' => 'purchase', 'id' => $this->golf->id], $files->get('items', 0, 'owner'));

        $fill = $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40000', '40', '60');
        $readings = ApiClient::json($this->api->get($this->base . '/odometer'));
        $derived = array_search($fill->id, $readings->column('source_id', 'items'), true);
        self::assertIsInt($derived);
        $derivedFiles = $this->base . '/odometer/' . $readings->int('items', $derived, 'id') . '/attachments';
        $refused = $this->api->upload($derivedFiles, self::PDF, 'x.pdf');
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('reading_derived', ApiClient::json($refused)->get('code'));
    }

    public function testAnArchivedVehicleKeepsItsFilesButAValuationsMayChange(): void
    {
        $fill = $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '40000', '40', '60');
        $fuelFiles = $this->base . '/fuel/' . $fill->id . '/attachments';
        $file = ApiClient::json($this->api->upload($fuelFiles, self::PDF, 'r.pdf'))->int('id');
        $valuation = ApiClient::json($this->api->post($this->base . '/valuations', ['amount' => 9000, 'source' => 'Dealer']))
            ->int('entry', 'id');
        $archivedAt = new DateTimeImmutable('2026-09-30T12:00:00Z');
        $this->service($this->app, VehicleRepository::class)
            ->setStatus($this->owner->id, $this->golf->id, VehicleStatus::Archived, $archivedAt);

        self::assertSame('vehicle_archived', ApiClient::json($this->api->upload($fuelFiles, self::PDF, 'r.pdf'))->get('code'));
        self::assertSame(409, $this->api->delete('/attachments/' . $file)->getStatusCode());
        self::assertSame(200, $this->api->get('/attachments/' . $file)->getStatusCode());

        $quote = $this->api->upload($this->base . '/valuations/' . $valuation . '/attachments', self::PDF, 'quote.pdf');
        self::assertSame(201, $quote->getStatusCode());
        self::assertSame(204, $this->api->delete('/attachments/' . ApiClient::json($quote)->int('id'))->getStatusCode());
    }

    public function testTheVehiclePhotoHasItsOwnPath(): void
    {
        $photo = $this->base . '/photo';
        self::assertSame(404, $this->api->get($photo)->getStatusCode());

        $refused = $this->api->upload($photo, self::PDF, 'car.pdf');
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('upload.not_an_image', ApiClient::json($refused)->get('errors', 'file', 'key'));

        $car = ExifJpeg::make(40, 20, 6);
        self::assertSame(204, $this->api->upload($photo, $car, 'car.jpg', 'image/jpeg')->getStatusCode());
        $served = $this->api->get($photo);
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/jpeg', $served->getHeaderLine('Content-Type'));
        self::assertSame([], $this->service($this->app, AttachmentRepository::class)->listForVehicle($this->golf->id));

        $driver = $this->share('driver', ShareLevel::Log);
        self::assertSame(200, $driver->get($photo)->getStatusCode());
        self::assertSame(403, $driver->delete($photo)->getStatusCode());

        self::assertSame(204, $this->api->delete($photo)->getStatusCode());
        self::assertSame(404, $this->api->get($photo)->getStatusCode());
    }

    private function share(string $username, ShareLevel $level, bool $costs = true): ApiClient
    {
        $user = $this->createMember($this->app, $username);
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $user->id, $level, $costs, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));

        return $this->api($this->app, $this->apiKey($this->app, $user));
    }
}
