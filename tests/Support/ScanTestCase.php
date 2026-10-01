<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use DI\Container;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Ai\Scan\PdfRenderer;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Boots the app for the scan tests (spec.md §7.27 *Tests*): a vision model
 * on the network for `read_document` and `read_text`, a scripted provider,
 * a PDF renderer the test controls, and an owner with the fixture garage:
 * Golf AB12 CDE, BMW 320d XY34 ZZZ and Leaf EV70 LTR.
 */
abstract class ScanTestCase extends AiTestCase
{
    protected const string NOW = '2026-10-15T12:00:00Z';
    protected const string FIXTURES = __DIR__ . '/../Fixtures/scans';

    /** @var App<ContainerInterface> */
    protected App $app;
    protected User $owner;
    protected TestBrowser $browser;
    /** @var array<string, Vehicle> by model: Golf, BMW, Leaf */
    protected array $garage = [];
    protected FakeRenderer $renderer;
    /** @var list<string> */
    private array $tempFiles = [];

    /**
     * @param array<string, string> $env
     * @param list<Capability> $readerCapabilities what the `read_document` model takes
     */
    protected function scanApp(
        array $env = [],
        ?DisplayPreferences $preferences = null,
        array $readerCapabilities = [Capability::Json, Capability::Images],
    ): void {
        $this->app = $this->aiApp($env + ['UPLOAD_PATH' => $this->uploadDir()]);
        $this->pinClock($this->app, self::NOW);
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app, 'owner', $preferences);
        $connection = $this->network($this->app, 'Ollama on the desktop', 'http://192.168.1.20:11434/v1');
        $this->assign($this->app, $connection, 'qwen2.5vl:7b', AiTaskName::ReadDocument, $readerCapabilities);
        $this->assign($this->app, $connection, 'llama3.2:3b', AiTaskName::ReadText, [Capability::Json]);
        $this->renderer = new FakeRenderer();
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(PdfRenderer::class, $this->renderer);

        $vehicles = $this->service($this->app, VehicleService::class);
        $this->garage = [
            'Golf' => $vehicles->create($this->owner, new VehicleData(
                VehicleType::Car,
                'Volkswagen',
                'Golf',
                FuelType::Petrol,
                registration: 'AB12 CDE',
                firstRegisteredOn: new DateTimeImmutable('2018-03-01', new DateTimeZone('UTC')),
            )),
            'BMW' => $vehicles->create($this->owner, new VehicleData(
                VehicleType::Car,
                'BMW',
                '320d',
                FuelType::Diesel,
                registration: 'XY34 ZZZ',
            )),
            'Leaf' => $vehicles->create($this->owner, new VehicleData(
                VehicleType::Car,
                'Nissan',
                'Leaf',
                FuelType::Electric,
                registration: 'EV70 LTR',
            )),
        ];
        $this->browser = $this->browserFor($this->app, 'owner');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    /**
     * The model's next reply: this object as its JSON answer.
     *
     * @param array<string, mixed> $object
     */
    protected function reply(array $object): void
    {
        $this->provider->queue(ScriptedProvider::answer(json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    /**
     * POST /scan with these bytes; the response is the redirect to the result.
     *
     * @param array<string, string> $fields
     */
    protected function scan(string $bytes, string $name, string $mime, array $fields = []): ResponseInterface
    {
        return $this->browser->post('/scan', $fields, ['file' => $this->upload($bytes, $name, $mime)]);
    }

    /**
     * Follow redirects until a page answers, collecting where they went.
     *
     * @return array{ResponseInterface, list<string>}
     */
    protected function land(ResponseInterface $response): array
    {
        $path = [];
        while (in_array($response->getStatusCode(), [302, 303], true)) {
            $path[] = $response->getHeaderLine('Location');
            $response = $this->browser->follow($response);
        }

        return [$response, $path];
    }

    protected function upload(string $bytes, string $name, string $mime): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-scan-test-');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, $mime, strlen($bytes), UPLOAD_ERR_OK);
    }

    /**
     * A fixture's JSON (manifest.php as build.php wrote it) and its file's bytes.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected static function fixture(string $name): array
    {
        $json = json_decode((string) file_get_contents(self::FIXTURES . '/' . $name . '.json'), true);
        self::assertIsArray($json);
        $file = $json['file'] ?? null;
        self::assertIsString($file);

        return [$json, (string) file_get_contents(self::FIXTURES . '/' . $file)];
    }

    /**
     * @return list<string> every fixture's name, in order
     */
    protected static function fixtureNames(): array
    {
        $names = array_map(
            static fn (string $path): string => basename($path, '.json'),
            glob(self::FIXTURES . '/[0-9][0-9]-*.json') ?: [],
        );
        sort($names);

        return $names;
    }

    /**
     * The JSON body the $index-th model request sent.
     *
     * @return array<mixed>
     */
    protected function request(int $index): array
    {
        $json = $this->provider->requests[$index]['json'] ?? null;
        self::assertIsArray($json, 'no request ' . $index);

        return $json;
    }

    /**
     * Every image sent in the $index-th request, decoded.
     *
     * @return list<string>
     */
    protected function sentImages(int $index): array
    {
        $images = [];
        $messages = $this->request($index)['messages'] ?? [];
        foreach (is_array($messages) ? $messages : [] as $message) {
            $parts = is_array($message) && is_array($message['content'] ?? null) ? $message['content'] : [];
            foreach ($parts as $part) {
                $url = is_array($part) && is_array($part['image_url'] ?? null) ? ($part['image_url']['url'] ?? '') : '';
                if (is_string($url) && preg_match('#^data:image/[a-z]+;base64,(.+)$#', $url, $m) === 1) {
                    $images[] = (string) base64_decode($m[1], true);
                }
            }
        }

        return $images;
    }

    /**
     * All the text the $index-th request sent (system and user).
     */
    protected function sentText(int $index): string
    {
        return (string) json_encode($this->request($index)['messages'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
