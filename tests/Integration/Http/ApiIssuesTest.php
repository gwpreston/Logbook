<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IssueRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The issue endpoints (Phase 40.2, spec.md §7.20 *Issues*, §7.37): logging
 * through the form with a safe retry, the lists, one issue with its
 * timeline and `ETag`, `PATCH` and `DELETE`, updates, fix and reopen, files,
 * access and the module. ApiClient checks every response against
 * openapi.json.
 */
final class ApiIssuesTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $this->path = '/vehicles/' . $this->golf->id . '/issues';
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function issue(array $body = []): array
    {
        return $body + [
            'noticed_on' => '2026-08-12',
            'title' => 'Knock from front left under braking',
            'description' => 'Only when cold',
            'odometer' => '41000',
            'distance_unit' => 'km',
            'category' => 'brakes',
            'affects_safety' => true,
        ];
    }

    private function logged(array $body = []): int
    {
        $response = $this->api->post($this->path, self::issue($body));
        self::assertSame(201, $response->getStatusCode(), self::body($response));

        return ApiClient::json($response)->int('entry', 'id');
    }

    private function record(string $on, string $title = 'Front brake pads'): int
    {
        $response = $this->api->post('/vehicles/' . $this->golf->id . '/maintenance', [
            'performed_on' => $on, 'category' => 'brakes', 'title' => $title, 'odometer' => '41500', 'distance_unit' => 'km',
        ]);
        self::assertSame(201, $response->getStatusCode(), self::body($response));

        return ApiClient::json($response)->int('entry', 'id');
    }

    public function testAPostLogsTheIssueAndARetryWritesNothing(): void
    {
        $response = $this->api->post($this->path, self::issue());
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');
        self::assertSame('Knock from front left under braking', $entry->get('title'));
        self::assertSame('open', $entry->get('status'));
        self::assertTrue($entry->get('affects_safety'));
        self::assertSame('41000.000', $entry->get('odometer'));
        self::assertSame('brakes', $entry->get('category'));
        self::assertSame('manual', $entry->get('source'));
        self::assertSame([], $entry->get('updates'));

        $retry = $this->api->post($this->path, self::issue());
        self::assertSame(200, $retry->getStatusCode());
        self::assertTrue(ApiClient::json($retry)->get('duplicate'));
        self::assertCount(1, $this->service($this->app, IssueRepository::class)->listForVehicle($this->golf->id));
    }

    public function testInvalidInputIsRefusedWithTheApiFieldNames(): void
    {
        $response = $this->api->post($this->path, self::issue([
            'noticed_on' => '2026-10-02',
            'title' => str_repeat('x', 121),
            'status' => 'fixed',
            'look_again_on' => '2026-12-01',
        ]));
        self::assertSame(422, $response->getStatusCode());
        $fields = ApiClient::json($response)->keys('errors');
        self::assertContains('status', $fields, 'fixing is POST …/fix');
        self::assertContains('affects_safety', ApiClient::json(
            $this->api->post($this->path, self::issue(['affects_safety' => 'yes'])),
        )->keys('errors'));
        $watching = $this->api->post($this->path, self::issue([
            'noticed_on' => '2026-10-02', 'title' => str_repeat('x', 121),
        ]));
        $fields = ApiClient::json($watching)->keys('errors');
        self::assertContains('noticed_on', $fields);
        self::assertContains('title', $fields);
    }

    public function testListsFilterByStatusAndTheFleetListTakesEveryVehicle(): void
    {
        $open = $this->logged();
        $watched = $this->logged([
            'noticed_on' => '2026-09-01', 'title' => 'Brake pipes corroded', 'status' => 'watching',
            'look_again_on' => '2026-12-01', 'affects_safety' => false,
        ]);
        $fiesta = $this->vehicle($this->app, 'Ford', 'Fiesta');
        $theirs = ApiClient::json($this->api->post('/vehicles/' . $fiesta->id . '/issues', self::issue(['title' => 'Slow leak'])))
            ->int('entry', 'id');

        $all = ApiClient::json($this->api->get($this->path));
        self::assertSame([$watched, $open], $all->column('id', 'items'), 'newest noticed first');
        self::assertSame([$watched], ApiClient::json($this->api->get($this->path . '?status=watching'))->column('id', 'items'));
        self::assertSame('2026-12-01', ApiClient::json($this->api->get($this->path . '/' . $watched))->get('look_again_on'));
        self::assertSame(400, $this->api->get($this->path . '?status=closed')->getStatusCode());

        $fleet = ApiClient::json($this->api->get('/issues?status=open'));
        self::assertSame([$theirs, $open], $fleet->column('id', 'items'));
        self::assertSame([$theirs], ApiClient::json($this->api->get('/issues?vehicle=' . $fiesta->id))->column('id', 'items'));
        $this->service($this->app, VehicleService::class)->archive($this->owner, $fiesta);
        $active = ApiClient::json($this->api->get('/issues'))->column('id', 'items');
        self::assertSame([$watched, $open], $active, 'active vehicles only');
    }

    public function testPatchKeepsWhatIsNotSentAndHonoursIfMatch(): void
    {
        $id = $this->logged();
        $read = $this->api->get($this->path . '/' . $id);
        $tag = $read->getHeaderLine('ETag');
        self::assertNotSame('', $tag);

        $edited = $this->api->patch(
            $this->path . '/' . $id,
            ['title' => 'Knock, front left', 'description' => null],
            ['If-Match' => $tag],
        );
        self::assertSame(200, $edited->getStatusCode(), self::body($edited));
        $entry = ApiClient::json($edited)->doc('entry');
        self::assertSame('Knock, front left', $entry->get('title'));
        self::assertNull($entry->get('description'));
        self::assertSame('41000.000', $entry->get('odometer'), 'kept to the metre');
        self::assertTrue($entry->get('affects_safety'));

        $stale = $this->api->patch($this->path . '/' . $id, ['title' => 'Again'], ['If-Match' => $tag]);
        self::assertSame(412, $stale->getStatusCode());

        $watch = $this->api->patch($this->path . '/' . $id, ['status' => 'watching', 'look_again_odometer' => '45000']);
        self::assertSame('watching', ApiClient::json($watch)->get('entry', 'status'));
        self::assertTrue(ApiClient::json($watch)->get('entry', 'updates', 0, 'automatic'));
    }

    public function testAnUpdateFixAndReopenEachChangeTheTag(): void
    {
        $id = $this->logged();
        $tag = $this->api->get($this->path . '/' . $id)->getHeaderLine('ETag');

        $noted = $this->api->post($this->path . '/' . $id . '/updates', [
            'noted_on' => '2026-09-01', 'note' => 'Worse when cold', 'odometer' => '41200', 'distance_unit' => 'km',
        ]);
        self::assertSame(201, $noted->getStatusCode(), self::body($noted));
        self::assertNotSame($tag, $noted->getHeaderLine('ETag'), 'a note changes the tag');
        self::assertSame('Worse when cold', ApiClient::json($noted)->get('entry', 'updates', 0, 'note'));
        self::assertSame(412, $this->api->post($this->path . '/' . $id . '/reopen', [], ['If-Match' => $tag])->getStatusCode());

        $older = $this->record('2026-08-01', 'Before it was noticed');
        $refused = $this->api->post($this->path . '/' . $id . '/fix', ['records' => [$older]]);
        self::assertSame(422, $refused->getStatusCode(), 'a record before it was noticed');
        self::assertSame(422, $this->api->post($this->path . '/' . $id . '/fix', ['records' => [999999]])->getStatusCode());

        $record = $this->record('2026-09-20');
        $fixed = $this->api->post($this->path . '/' . $id . '/fix', ['records' => [$record]]);
        self::assertSame(200, $fixed->getStatusCode(), self::body($fixed));
        $entry = ApiClient::json($fixed)->doc('entry');
        self::assertSame('fixed', $entry->get('status'));
        self::assertSame('2026-09-20', $entry->get('fixed_on'));
        self::assertSame([$record], $entry->get('fixed_by'));
        self::assertFalse($entry->get('fixed_without_record'));
        self::assertFalse(ApiClient::json($fixed)->get('unchanged'));

        $again = $this->api->post($this->path . '/' . $id . '/fix', ['records' => [$record]]);
        self::assertTrue(ApiClient::json($again)->get('unchanged'), 'a retry changes nothing');
        $open = $this->api->patch($this->path . '/' . $id, ['status' => 'open']);
        self::assertSame(422, $open->getStatusCode(), 'reopen is …/reopen');
        self::assertSame(200, $this->api->patch($this->path . '/' . $id, ['title' => 'Still fixed'])->getStatusCode());

        $back = ApiClient::json($this->api->post($this->path . '/' . $id . '/reopen', []));
        self::assertSame('open', $back->get('entry', 'status'));
        self::assertSame([], $back->get('entry', 'fixed_by'), 'the earlier fix is history');
        self::assertTrue(ApiClient::json($this->api->post($this->path . '/' . $id . '/reopen', []))->get('unchanged'));

        $without = ApiClient::json(
            $this->api->post($this->path . '/' . $id . '/fix', ['fixed_on' => '2026-09-29', 'note' => 'Went away']),
        );
        self::assertSame('fixed', $without->get('entry', 'status'));
        self::assertTrue($without->get('entry', 'fixed_without_record'));
        self::assertSame('2026-09-29', $without->get('entry', 'fixed_on'));
        $both = $this->api->post($this->path . '/' . $id . '/fix', ['records' => [$record], 'note' => 'x']);
        self::assertSame(422, $both->getStatusCode());
    }

    public function testDeleteRemovesTheIssueAndAccessFollowsEntryAccess(): void
    {
        $id = $this->logged();
        $member = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-30T12:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $member));

        self::assertSame(200, $theirs->get($this->path . '/' . $id)->getStatusCode());
        $mine = $theirs->patch($this->path . '/' . $id, ['title' => 'Mine now']);
        self::assertSame(403, $mine->getStatusCode(), 'someone else\'s');
        self::assertSame(403, $theirs->delete($this->path . '/' . $id)->getStatusCode());
        $heard = $theirs->post($this->path . '/' . $id . '/updates', ['note' => 'Heard it too']);
        self::assertSame(201, $heard->getStatusCode(), 'Log adds updates');
        $own = ApiClient::json($theirs->post($this->path, self::issue(['title' => 'Rattle'])))->int('entry', 'id');
        self::assertSame(200, $theirs->patch($this->path . '/' . $own, ['title' => 'Rattle, rear'])->getStatusCode());

        $viewer = $this->createMember($this->app, 'viewer');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-30T12:00:00Z'));
        $looking = $this->api($this->app, $this->apiKey($this->app, $viewer));
        self::assertSame(200, $looking->get($this->path)->getStatusCode());
        self::assertSame(403, $looking->post($this->path, self::issue(['title' => 'Nope']))->getStatusCode());
        self::assertSame(403, $looking->post($this->path . '/' . $id . '/reopen', [])->getStatusCode());

        self::assertSame(204, $this->api->delete($this->path . '/' . $id)->getStatusCode());
        self::assertSame(404, $this->api->get($this->path . '/' . $id)->getStatusCode());
        self::assertNull($this->service($this->app, OdometerReadingRepository::class)
            ->findByEntry($this->golf->id, OdometerSource::Issue, $id));
    }

    public function testFilesAttachToAnIssue(): void
    {
        $id = $this->logged();
        $path = $this->path . '/' . $id . '/attachments';
        $uploaded = $this->api->upload($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 'advisory.pdf');
        self::assertSame(201, $uploaded->getStatusCode(), self::body($uploaded));
        self::assertCount(1, ApiClient::json($this->api->get($path))->doc('items')->toArray());
    }

    public function testAnArchivedVehicleTakesNoWrites(): void
    {
        $id = $this->logged();
        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);
        self::assertSame(409, $this->api->post($this->path, self::issue(['title' => 'Later']))->getStatusCode());
        self::assertSame(409, $this->api->post($this->path . '/' . $id . '/updates', ['note' => 'x'])->getStatusCode());
        self::assertSame(409, $this->api->post($this->path . '/' . $id . '/reopen', [])->getStatusCode());
        self::assertSame(200, $this->api->get($this->path . '/' . $id)->getStatusCode());
    }

    public function testNoSecondReadingWhereTheVehicleHasOneThatDayAtTheSameOdometer(): void
    {
        // #319: the service record's reading already says it.
        $this->record('2026-09-20');
        $id = $this->logged(['noticed_on' => '2026-09-20', 'odometer' => '41500']);
        $readings = $this->service($this->app, OdometerReadingRepository::class);
        self::assertNull($readings->findByEntry($this->golf->id, OdometerSource::Issue, $id));
        $kept = ApiClient::json($this->api->get($this->path . '/' . $id))->get('odometer');
        self::assertSame('41500.000', $kept, 'the issue keeps it');

        $other = $this->logged(['noticed_on' => '2026-09-20', 'odometer' => '41600', 'title' => 'Squeal']);
        $reading = $readings->findByEntry($this->golf->id, OdometerSource::Issue, $other);
        self::assertNotNull($reading, 'another odometer is a reading');
    }

    public function testWithTheModuleOffEveryIssuePathIsNotFound(): void
    {
        $id = $this->logged();
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Issues)));

        self::assertSame(404, $this->api->get($this->path)->getStatusCode());
        self::assertSame(404, $this->api->post($this->path, self::issue())->getStatusCode());
        self::assertSame(404, $this->api->get($this->path . '/' . $id)->getStatusCode());
        self::assertSame(404, $this->api->post($this->path . '/' . $id . '/fix', [])->getStatusCode());
        self::assertSame(404, $this->api->get('/issues')->getStatusCode());
        self::assertSame(404, $this->api->get($this->path . '/' . $id . '/attachments')->getStatusCode());
    }
}
