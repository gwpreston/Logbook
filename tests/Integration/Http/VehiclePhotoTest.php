<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Kernel;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

final class VehiclePhotoTest extends AppTestCase
{
    /** A valid 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const array BIKE = ['type' => 'bike', 'make' => 'Triumph', 'model' => 'Street Triple R', 'fuel_type' => 'petrol'];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        array_map(static fn (string $file) => is_file($file) && unlink($file), $this->tempFiles);
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testUploadStoresOutsideTheWebRootAndServesOnlyWhenSignedIn(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        $response = $browser->post('/vehicles/new', self::BIKE, ['photo' => $this->upload(base64_decode(self::PNG), 'bike.png')]);
        self::assertSame(303, $response->getStatusCode());

        $vehicle = $this->onlyVehicle($app);
        self::assertNotNull($vehicle->photoPath);
        self::assertSame('image/png', $vehicle->photoMime);
        // A random name, never the uploaded one.
        self::assertMatchesRegularExpression('~^vehicles/[a-f0-9]{32}\.png$~', $vehicle->photoPath);
        self::assertFileExists($this->uploadDir() . '/' . $vehicle->photoPath);
        self::assertFileDoesNotExist(Kernel::rootDir() . '/public/' . $vehicle->photoPath);

        $show = self::body($browser->get('/vehicles/' . $vehicle->id));
        $photoUrl = '/vehicles/' . $vehicle->id . '/photo?v=' . $vehicle->photoVersion();
        self::assertStringContainsString('src="' . $photoUrl . '"', $show);

        $photo = $browser->get($photoUrl);
        self::assertSame(200, $photo->getStatusCode());
        self::assertSame('image/png', $photo->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $photo->getHeaderLine('X-Content-Type-Options'));
        self::assertStringStartsWith('private', $photo->getHeaderLine('Cache-Control'));
        self::assertSame(base64_decode(self::PNG), self::body($photo));

        $cached = $browser->get($photoUrl, ['If-None-Match' => $photo->getHeaderLine('ETag')]);
        self::assertSame(304, $cached->getStatusCode());

        $anonymous = (new TestBrowser($app))->get($photoUrl);
        self::assertSame(303, $anonymous->getStatusCode());
        self::assertStringStartsWith('/login', $anonymous->getHeaderLine('Location'));
    }

    public function testReplaceAndRemoveDeleteTheOldFile(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->post('/vehicles/new', self::BIKE, ['photo' => $this->upload(base64_decode(self::PNG), 'a.png')]);
        $first = $this->onlyVehicle($app);

        $replacement = $this->upload(base64_decode(self::PNG), 'b.png');
        $browser->post('/vehicles/' . $first->id . '/edit', self::BIKE, ['photo' => $replacement]);
        $second = $this->onlyVehicle($app);
        self::assertNotSame($first->photoPath, $second->photoPath);
        self::assertNotSame($first->photoVersion(), $second->photoVersion(), 'URL changes, so caches refresh');
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $first->photoPath);
        self::assertFileExists($this->uploadDir() . '/' . $second->photoPath);

        $browser->post('/vehicles/' . $first->id . '/edit', self::BIKE + ['remove_photo' => '1']);
        $third = $this->onlyVehicle($app);
        self::assertNull($third->photoPath);
        self::assertFileDoesNotExist($this->uploadDir() . '/' . $second->photoPath);
        self::assertSame(404, $browser->get('/vehicles/' . $first->id . '/photo')->getStatusCode());
    }

    public function testDeletingTheVehicleDeletesItsPhoto(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->post('/vehicles/new', self::BIKE, ['photo' => $this->upload(base64_decode(self::PNG), 'a.png')]);
        $vehicle = $this->onlyVehicle($app);
        self::assertNotNull($vehicle->photoPath);

        $browser->post('/vehicles/' . $vehicle->id . '/delete');

        self::assertFileDoesNotExist($this->uploadDir() . '/' . $vehicle->photoPath);
    }

    public function testRejectsNonImagesWhateverTheirName(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        $script = $this->upload('<?php echo "hi";', 'photo.png', 'image/png');
        $response = $browser->post('/vehicles/new', self::BIKE, ['photo' => $script]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Choose a JPEG, PNG or WebP image.', self::body($response));
        self::assertSame([], $this->allVehicles($app), 'nothing is saved when the photo is rejected');
        self::assertDirectoryDoesNotExist($this->uploadDir() . '/vehicles');
    }

    public function testRejectsFilesOverTheSizeLimit(): void
    {
        $app = $this->createApp(['MAX_UPLOAD_MB' => '1']);
        $browser = $this->signedIn($app);
        $browser->get('/vehicles/new');

        $big = base64_decode(self::PNG) . str_repeat("\0", 1024 * 1024 + 1);
        $response = $browser->post('/vehicles/new', self::BIKE, ['photo' => $this->upload($big, 'big.png')]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('The file is too large (maximum 1 MB).', self::body($response));
    }

    public function testAnEmptyFileInputIsIgnored(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);

        $none = new UploadedFile($this->tempFile(''), '', 'application/octet-stream', 0, UPLOAD_ERR_NO_FILE);
        $response = $browser->post('/vehicles/new', self::BIKE, ['photo' => $none]);

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->onlyVehicle($app)->photoPath);
    }

    /** The CSS that crops a tall photo on the pinned card relies on this nesting (spec.md §7.1). */
    public function testThePinnedCardKeepsThePhotoInsideItsFrame(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $browser->post('/vehicles/new', self::BIKE, ['photo' => $this->upload(base64_decode(self::PNG), 'bike.png')]);
        $vehicle = $this->onlyVehicle($app);

        $dashboard = Html::document(self::body($browser->get('/?vehicle=' . $vehicle->id)));
        $img = Html::element($dashboard, '[data-pinned-vehicle] > .pinned__media > .vehicle-photo > img');
        self::assertStringStartsWith('/vehicles/' . $vehicle->id . '/photo', (string) $img->getAttribute('src'));
    }

    private function upload(string $contents, string $name, string $type = 'image/png'): UploadedFile
    {
        $path = $this->tempFile($contents);

        return new UploadedFile($path, $name, $type, strlen($contents), UPLOAD_ERR_OK);
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Vehicle>
     */
    private function allVehicles(App $app): array
    {
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $this->ownedVehicles($app, $owner->id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function onlyVehicle(App $app): Vehicle
    {
        $vehicles = $this->allVehicles($app);
        self::assertCount(1, $vehicles);

        return $vehicles[0];
    }
}
