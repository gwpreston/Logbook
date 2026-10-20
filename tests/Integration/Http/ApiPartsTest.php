<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
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
 * The parts and modifications endpoints (Phase 50.2, spec.md §7.20 *Parts and modifications*,
 * §7.39): logging through the forms with a safe retry, the lists, one entry with its `ETag`,
 * `PATCH` and `DELETE`, fit, remove, revert and undo, files, costs only with `ViewCosts` (#429),
 * access and the module. ApiClient checks every response against openapi.json.
 */
final class ApiPartsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string NOW = '2026-09-30T12:00:00Z';

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;
    private string $mods;
    private string $parts;
    private \Logbook\Tests\Support\QueryCounter $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_PARTS' => 'true']);
        // Before the database is opened, or it counts nothing.
        $this->counter = \Logbook\Tests\Support\QueryCounter::install($this->app);
        $this->pinClock($this->app, self::NOW);
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $this->mods = '/vehicles/' . $this->golf->id . '/modifications';
        $this->parts = '/vehicles/' . $this->golf->id . '/parts';
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
    private static function mod(array $body = []): array
    {
        return $body + [
            'title' => 'Cat-back exhaust',
            'category' => 'exhaust',
            'done_on' => '2026-05-10',
            'odometer' => '37000',
            'distance_unit' => 'km',
            'cost' => '899.00',
            'fitted_by' => 'Self',
            'parts_fitted' => [[
                'name' => 'Cat-back system', 'brand' => 'Milltek', 'part_number' => 'SSXVW123', 'price' => '780.00',
                'warranty_months' => 24, 'bought_on' => '2026-05-01',
            ]],
            'parts_removed' => [['came_off' => ['name' => 'Standard exhaust', 'outcome' => 'kept']]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function logged(array $body = []): int
    {
        $response = $this->api->post($this->mods, self::mod($body));
        self::assertSame(201, $response->getStatusCode(), self::body($response));

        return ApiClient::json($response)->int('entry', 'id');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function part(array $body = []): int
    {
        $response = $this->api->post($this->parts, $body + ['name' => 'Spare shock', 'bought_on' => '2026-04-01']);
        self::assertSame(201, $response->getStatusCode(), self::body($response));

        return ApiClient::json($response)->int('entry', 'id');
    }

    public function testAModificationWithItsPartsIsOneSaveAndARetryWritesNothing(): void
    {
        $response = $this->api->post($this->mods, self::mod());
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');
        self::assertSame('Cat-back exhaust', $entry->get('title'));
        self::assertSame('37000.000', $entry->get('odometer'));
        self::assertSame('899.000', $entry->get('cost'));
        self::assertNull($entry->get('declared_on'));
        self::assertSame('Milltek Cat-back system', $entry->get('parts_fitted', 0, 'name'));
        self::assertSame('Standard exhaust', $entry->get('parts_removed', 0, 'name'));
        self::assertSame('kept', $entry->get('parts_removed', 0, 'outcome'));
        $read = $this->api->get($this->mods . '/' . ApiClient::json($response)->int('entry', 'id'));
        self::assertNotSame('', $read->getHeaderLine('ETag'));

        $retry = $this->api->post($this->mods, self::mod());
        self::assertSame(200, $retry->getStatusCode());
        self::assertTrue(ApiClient::json($retry)->get('duplicate'));
        self::assertCount(2, ApiClient::json($this->api->get($this->parts))->doc('items')->toArray(), 'no parts written twice');
    }

    public function testARetryNamingAStoredPartIsTheDuplicateNotARefusal(): void
    {
        // K27: the first call fitted (or removed) the part, so the lists no longer offer it on the retry.
        $spare = $this->part();
        $fit = self::mod(['title' => 'Coilovers', 'parts_fitted' => [['part' => $spare]], 'parts_removed' => []]);
        $first = $this->api->post($this->mods, $fit);
        self::assertSame(201, $first->getStatusCode(), self::body($first));
        $retry = $this->api->post($this->mods, $fit);
        self::assertSame(200, $retry->getStatusCode(), self::body($retry));
        self::assertTrue(ApiClient::json($retry)->get('duplicate'));
        self::assertSame(ApiClient::json($first)->int('entry', 'id'), ApiClient::json($retry)->int('entry', 'id'));

        $remove = self::mod([
            'title' => 'Back to standard', 'done_on' => '2026-06-01', 'odometer' => '38000',
            'parts_fitted' => [], 'parts_removed' => [['part' => $spare, 'outcome' => 'kept']],
        ]);
        $off = $this->api->post($this->mods, $remove);
        self::assertSame(201, $off->getStatusCode(), self::body($off));
        $again = $this->api->post($this->mods, $remove);
        self::assertSame(200, $again->getStatusCode(), self::body($again));
        self::assertTrue(ApiClient::json($again)->get('duplicate'));
        $part = ApiClient::json($this->api->get($this->parts . '/' . $spare));
        self::assertSame('stored', $part->get('status'), 'the retry changed no part');
        self::assertCount(1, $part->doc('fittings')->toArray(), 'fitted once, removed once');
    }

    public function testInvalidInputIsRefusedWithTheApiFieldNames(): void
    {
        $response = $this->api->post($this->mods, self::mod([
            'title' => str_repeat('x', 121),
            'done_on' => '2026-12-01',
            'parts_fitted' => [['name' => 'Fine'], ['name' => 'Cheap', 'price' => '-5']],
            'parts_removed' => [],
        ]));
        self::assertSame(422, $response->getStatusCode());
        $fields = ApiClient::json($response)->keys('errors');
        self::assertContains('title', $fields);
        self::assertContains('done_on', $fields);
        self::assertContains('parts_fitted.1.price', $fields, 'the form\'s errors under the body\'s names');
        $offered = $this->api->post($this->mods, self::mod(['parts_removed' => [['part' => 999999]]]));
        self::assertContains('parts_removed', ApiClient::json($offered)->keys('errors'), 'a part the list does not offer');
        $blank = $this->api->post($this->mods, self::mod(['parts_fitted' => [[]]]));
        self::assertSame(422, $blank->getStatusCode(), 'a row with no name is refused, not dropped');
        self::assertContains('parts_fitted.0.name', ApiClient::json($blank)->keys('errors'));
        $types = $this->api->post($this->mods, self::mod(['parts_fitted' => [['name' => 'Fine', 'price' => 'free']]]));
        self::assertContains('parts_fitted.0.price', ApiClient::json($types)->keys('errors'));
        $unknown = $this->api->post($this->mods, self::mod(['colour' => 'red']));
        self::assertContains('colour', ApiClient::json($unknown)->keys('errors'));
        self::assertSame([], ApiClient::json($this->api->get($this->mods))->column('id', 'items'), 'nothing is written on a 422');
    }

    public function testTheModificationListReadsTheFittingsOnceForThePage(): void
    {
        $counter = $this->counter;
        foreach (['A', 'B', 'C', 'D'] as $title) {
            $this->logged(['title' => $title, 'parts_fitted' => [], 'parts_removed' => []]);
        }
        $this->api->get($this->mods);
        $few = $counter->during(fn () => $this->api->get($this->mods . '?limit=1'));
        foreach (['E', 'F', 'G', 'H', 'I', 'J'] as $title) {
            $this->logged(['title' => $title, 'parts_fitted' => [], 'parts_removed' => []]);
        }
        $many = $counter->during(fn () => $this->api->get($this->mods . '?limit=1'));

        self::assertGreaterThan(0, $few, 'the counter sees the requests');
        self::assertSame($few, $many, 'the same queries however many modifications the vehicle holds');
    }

    public function testHistoryPagesOneItemAtATimeAcrossARevertedModification(): void
    {
        $id = $this->logged(['parts_fitted' => [], 'parts_removed' => []]);
        $this->api->post($this->mods . '/' . $id . '/revert', ['reverted_on' => '2026-07-01']);
        $next = '/vehicles/' . $this->golf->id . '/history?limit=1&kinds=modification_reverted,modification';
        $kinds = [];
        for ($i = 0; $i < 5 && $next !== null; $i++) {
            $page = $this->api->get($next);
            self::assertSame(200, $page->getStatusCode(), self::body($page));
            $doc = ApiClient::json($page);
            $kinds = [...$kinds, ...$doc->column('kind', 'items')];
            $link = $doc->get('next');
            $next = is_string($link) ? (string) preg_replace('~^.*?/api/v1~', '', $link) : null;
        }
        self::assertSame(['modification_reverted', 'modification'], $kinds, 'every page follows its cursor');
    }

    public function testAWebEditCannotMoveTheDateAfterTheDeclaration(): void
    {
        $id = $this->logged(['declared_on' => '2026-05-20']);
        $edit = $this->api->patch($this->mods . '/' . $id, ['done_on' => '2026-06-30']);
        self::assertSame(422, $edit->getStatusCode());
        self::assertContains('declared_on', ApiClient::json($edit)->keys('errors'));

        $form = \Logbook\Service\Part\ModificationForm::parse(
            ['title' => 'Cat-back exhaust', 'category' => 'exhaust', 'done_on' => '2026-06-30'],
            $this->owner->preferences,
            new \DateTimeImmutable('2026-09-30'),
            $this->service($this->app, \Logbook\Repository\ModificationRepository::class)->find($this->golf->id, $id),
        );
        self::assertInstanceOf(\Logbook\Support\Validation\ValidationErrors::class, $form, 'the page\'s form says so too');
    }

    public function testListErrorsNameTheRowTheClientSentAndNamelessRowsAreRefused(): void
    {
        $stored = $this->part(['name' => 'Spare']);
        $mixed = $this->api->post($this->mods, self::mod([
            'parts_fitted' => [
                ['part' => (string) $stored], ['name' => 'N', 'condition' => 'bogus'], ['name' => 'M', 'price' => '-1'],
            ],
            'parts_removed' => [],
        ]));
        $fields = ApiClient::json($mixed)->keys('errors');
        self::assertContains('parts_fitted.1.condition', $fields, 'the place in the body, whatever the rows before it');
        self::assertContains('parts_fitted.2.price', $fields);

        $nameless = $this->api->post($this->mods, self::mod([
            'parts_fitted' => [], 'parts_removed' => [['came_off' => ['outcome' => 'kept']]],
        ]));
        self::assertSame(422, $nameless->getStatusCode(), 'not dropped with a 201');
        self::assertContains('parts_removed.0.came_off.name', ApiClient::json($nameless)->keys('errors'));
    }

    public function testALongStringInABodyIsStillAJsonObject(): void
    {
        $note = str_repeat('é', 2200);
        $raw = json_encode(['title' => 'Long note', 'notes' => $note], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('\\u00e9', $raw, 'escaped, as PHP and Python write it');
        $response = $this->api->post($this->mods, $raw);

        self::assertSame(201, $response->getStatusCode(), self::body($response));
        self::assertSame($note, ApiClient::json($response)->get('entry', 'notes'));
    }

    public function testListsFilterAndOrder(): void
    {
        $first = $this->logged();
        $second = $this->logged([
            'title' => 'Stage 1 remap', 'category' => 'tune', 'done_on' => '2026-06-01',
            'parts_fitted' => [], 'parts_removed' => [],
        ]);
        $this->api->post($this->mods . '/' . $first . '/revert', ['reverted_on' => '2026-07-01']);

        $all = ApiClient::json($this->api->get($this->mods));
        self::assertSame([$second, $first], $all->column('id', 'items'), 'newest done first');
        self::assertSame([$second], ApiClient::json($this->api->get($this->mods . '?state=current'))->column('id', 'items'));
        self::assertSame([$first], ApiClient::json($this->api->get($this->mods . '?state=reverted'))->column('id', 'items'));
        self::assertSame(400, $this->api->get($this->mods . '?state=old')->getStatusCode());
        self::assertSame(400, $this->api->get($this->parts . '?status=old')->getStatusCode());
        $stored = ApiClient::json($this->api->get($this->parts . '?status=stored'))->column('name', 'items');
        self::assertSame(['Cat-back system'], $stored, 'reverting took the fitted part off, kept');
    }

    public function testPatchKeepsWhatIsNotSentAndHonoursIfMatch(): void
    {
        $id = $this->logged();
        $tag = $this->api->get($this->mods . '/' . $id)->getHeaderLine('ETag');

        $edited = $this->api->patch(
            $this->mods . '/' . $id,
            ['title' => 'Cat-back exhaust (Milltek)', 'notes' => 'Nice', 'declared_on' => '2026-06-01'],
            ['If-Match' => $tag],
        );
        self::assertSame(200, $edited->getStatusCode(), self::body($edited));
        $entry = ApiClient::json($edited)->doc('entry');
        self::assertSame('Cat-back exhaust (Milltek)', $entry->get('title'));
        self::assertSame('37000.000', $entry->get('odometer'), 'kept to the metre');
        self::assertSame('899.000', $entry->get('cost'));
        self::assertSame('2026-06-01', $entry->get('declared_on'));
        self::assertSame('Milltek Cat-back system', $entry->get('parts_fitted', 0, 'name'), 'the parts are untouched');
        $stale = $this->api->patch($this->mods . '/' . $id, ['title' => 'Again'], ['If-Match' => $tag]);
        self::assertSame(412, $stale->getStatusCode());

        $cleared = $this->api->patch($this->mods . '/' . $id, ['declared_on' => null, 'fitted_by' => null]);
        self::assertNull(ApiClient::json($cleared)->get('entry', 'declared_on'));
        self::assertNull(ApiClient::json($cleared)->get('entry', 'fitted_by'));
        $lists = $this->api->patch($this->mods . '/' . $id, ['parts_fitted' => []]);
        self::assertSame(422, $lists->getStatusCode(), 'the parts lists are not patchable');
        $early = $this->api->patch($this->mods . '/' . $id, ['declared_on' => '2026-05-01']);
        self::assertContains('declared_on', ApiClient::json($early)->keys('errors'), 'not before it was done');
    }

    public function testRevertAndUndoMoveThePartsWithIt(): void
    {
        $id = $this->logged();
        $reverted = $this->api->post($this->mods . '/' . $id . '/revert', [
            'reverted_on' => '2026-07-01', 'odometer' => '38000', 'distance_unit' => 'km', 'note' => 'Back to standard',
        ]);
        self::assertSame(200, $reverted->getStatusCode(), self::body($reverted));
        $entry = ApiClient::json($reverted)->doc('entry');
        self::assertSame('2026-07-01', $entry->get('reverted_on'));
        self::assertSame('38000.000', $entry->get('reverted_odometer'));
        self::assertFalse(ApiClient::json($reverted)->get('unchanged'));
        self::assertSame(
            ['Milltek Cat-back system'],
            $entry->column('name', 'parts_fitted'),
            'the revert\'s own fittings are not its parts',
        );
        self::assertNotContains('Milltek Cat-back system', $entry->column('name', 'parts_removed'));
        self::assertSame(['Standard exhaust'], $entry->column('name', 'parts_removed'));
        $list = ApiClient::json($this->api->get($this->parts));
        $names = $list->column('name', 'items');
        $at = array_search('Cat-back system', $names, true);
        $original = array_search('Standard exhaust', $names, true);
        self::assertIsInt($at);
        self::assertIsInt($original);
        self::assertSame('stored', $list->get('items', $at, 'status'), 'the fitted part came off (kept)');
        self::assertSame('fitted', $list->get('items', $original, 'status'), 'the original went back');
        self::assertTrue(ApiClient::json($this->api->post($this->mods . '/' . $id . '/revert', []))->get('unchanged'));

        $undone = ApiClient::json($this->api->post($this->mods . '/' . $id . '/undo-revert', []));
        self::assertNull($undone->get('entry', 'reverted_on'));
        self::assertFalse($undone->get('unchanged'));
        self::assertTrue(ApiClient::json($this->api->post($this->mods . '/' . $id . '/undo-revert', []))->get('unchanged'));
        $before = $this->api->post($this->mods . '/' . $id . '/revert', ['reverted_on' => '2026-01-01']);
        self::assertSame(422, $before->getStatusCode());
        self::assertContains('reverted_on', ApiClient::json($before)->keys('errors'));

        $keep = $this->api->post($this->mods . '/' . $id . '/revert', ['reverted_on' => '2026-07-02', 'remove_parts' => false]);
        self::assertSame(200, $keep->getStatusCode());
        $fitted = ApiClient::json($this->api->get($this->parts . '?status=fitted'))->column('name', 'items');
        self::assertContains('Cat-back system', $fitted, 'remove_parts false leaves the parts it fitted on the car');
    }

    public function testPartsFitRemoveAndTheirRefusals(): void
    {
        $id = $this->part([
            'brand' => 'Bilstein', 'part_number' => 'B14', 'price' => '300', 'warranty_months' => '24',
            'warranty_reminder' => true,
        ]);
        $path = $this->parts . '/' . $id;
        $read = ApiClient::json($this->api->get($path));
        self::assertSame('stored', $read->get('status'));
        self::assertSame('300.000', $read->get('price'));
        self::assertTrue($read->get('warranty_reminder'));
        self::assertSame('2028-04-01', $read->get('warranty', 'until'));
        self::assertTrue($read->get('warranty', 'active'));

        $removeFirst = $this->api->post($path . '/remove', ['outcome' => 'kept']);
        self::assertSame(422, $removeFirst->getStatusCode(), 'it is not fitted');
        $fitted = $this->api->post($path . '/fit', ['fitted_on' => '2026-05-02', 'odometer' => '36000', 'distance_unit' => 'km']);
        self::assertSame(200, $fitted->getStatusCode(), self::body($fitted));
        $part = ApiClient::json($fitted)->doc('entry');
        self::assertSame('fitted', $part->get('status'));
        self::assertSame('36000.000', $part->get('fittings', 0, 'fitted_odometer'));
        self::assertNull($part->get('fittings', 0, 'fitted_by'), 'no record made it');
        self::assertSame(422, $this->api->post($path . '/fit', [])->getStatusCode(), 'already fitted');

        $sold = $this->api->post($path . '/remove', [
            'removed_on' => '2026-08-01', 'odometer' => '37000', 'distance_unit' => 'km',
            'outcome' => 'sold', 'sold_price' => '120',
        ]);
        self::assertSame(200, $sold->getStatusCode(), self::body($sold));
        $gone = ApiClient::json($sold)->doc('entry');
        self::assertSame('gone', $gone->get('status'));
        self::assertSame('120.000', $gone->get('fittings', 0, 'sold_price'));
        self::assertSame('1000.000', $gone->get('distance'), 'its distance on the car');
        self::assertSame(422, $this->api->post($path . '/fit', [])->getStatusCode(), 'a sold part is not fitted again');
        $price = $this->api->post($this->parts . '/' . $this->part(['name' => 'Another']) . '/fit', []);
        $other = $this->parts . '/' . ApiClient::json($price)->int('entry', 'id');
        $sale = $this->api->post($other . '/remove', ['outcome' => 'kept', 'sold_price' => '5']);
        self::assertSame(422, $sale->getStatusCode(), 'a price only goes with sold');
    }

    public function testPartsPatchDuplicateAndDelete(): void
    {
        $id = $this->part(['part_number' => 'X1']);
        $retry = $this->api->post($this->parts, ['name' => 'Spare shock', 'bought_on' => '2026-04-01', 'part_number' => 'X1']);
        self::assertSame(200, $retry->getStatusCode());
        self::assertTrue(ApiClient::json($retry)->get('duplicate'));

        $path = $this->parts . '/' . $id;
        $tag = $this->api->get($path)->getHeaderLine('ETag');
        $body = ['brand' => 'Bilstein', 'warranty_months' => 12, 'warranty_reminder' => true];
        $edited = $this->api->patch($path, $body, ['If-Match' => $tag]);
        self::assertSame(200, $edited->getStatusCode(), self::body($edited));
        self::assertSame('Bilstein', ApiClient::json($edited)->get('entry', 'brand'));
        self::assertSame(412, $this->api->patch($path, ['brand' => 'Again'], ['If-Match' => $tag])->getStatusCode());
        $ended = $this->api->patch($path, ['bought_on' => '2020-01-01']);
        $refused = ApiClient::json($ended)->keys('errors');
        self::assertContains('warranty_reminder', $refused, 'a warranty that ended refuses the tick');

        self::assertSame(204, $this->api->delete($path)->getStatusCode());
        self::assertSame(404, $this->api->get($path)->getStatusCode());
    }

    public function testAPartsListOverTheLimitNamesTheLimit(): void
    {
        $rows = array_fill(0, 21, ['name' => 'Bolt']);
        $response = $this->api->post($this->mods, self::mod(['parts_fitted' => $rows]));
        self::assertSame(422, $response->getStatusCode());
        $error = ApiClient::json($response);
        self::assertSame('api.validation.rows', $error->get('errors', 'parts_fitted', 'key'));
        // K26: it said "Use a list of text values."
        self::assertStringContainsString('up to 20 objects', $error->string('errors', 'parts_fitted', 'message'));
    }

    public function testAModificationWriteReturnsTheOdometerWarning(): void
    {
        $this->reading($this->app, $this->golf, '50000', '2026-05-01T08:00:00Z');

        $backwards = $this->api->post($this->mods, self::mod());
        self::assertSame(201, $backwards->getStatusCode(), self::body($backwards));
        self::assertSame(['odometer_backwards'], ApiClient::json($backwards)->column('code', 'warnings'), 'K4');
        $path = $this->mods . '/' . ApiClient::json($backwards)->int('entry', 'id');

        $still = $this->api->patch($path, ['odometer' => '38000', 'distance_unit' => 'km']);
        self::assertSame(200, $still->getStatusCode(), self::body($still));
        self::assertSame(['odometer_backwards'], ApiClient::json($still)->column('code', 'warnings'));

        $fixed = $this->api->patch($path, ['odometer' => '50500', 'distance_unit' => 'km']);
        self::assertSame(200, $fixed->getStatusCode(), self::body($fixed));
        self::assertSame([], ApiClient::json($fixed)->column('code', 'warnings'));
    }

    public function testCostsNeedViewCostsAndAreIgnoredWithout(): void
    {
        $id = $this->logged();
        $member = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $member));

        $read = $theirs->get($this->mods . '/' . $id);
        $body = self::body($read);
        foreach (['"cost"', '"currency"', '"price"', '"sold_price"', '899', '780'] as $leak) {
            self::assertStringNotContainsString($leak, $body, 'no amount without Can see costs: ' . $leak);
        }
        $part = self::body($theirs->get($this->parts));
        self::assertStringNotContainsString('"price"', $part);

        $own = $theirs->post($this->mods, self::mod([
            'title' => 'Their lights', 'category' => 'lighting', 'cost' => '500',
            'parts_fitted' => [['name' => 'LEDs', 'price' => '40']], 'parts_removed' => [],
        ]));
        self::assertSame(201, $own->getStatusCode(), self::body($own));
        $made = ApiClient::json($this->api->get($this->mods . '/' . ApiClient::json($own)->int('entry', 'id')));
        self::assertSame('0.000', $made->get('cost'), 'a cost sent without the right is ignored');
        $parts = ApiClient::json($this->api->get($this->parts));
        $at = array_search('LEDs', $parts->column('name', 'items'), true);
        self::assertIsInt($at);
        self::assertNull($parts->get('items', $at, 'price'), 'so is a part price');
        $leds = $this->parts . '/' . $parts->int('items', $at, 'id');
        $sale = $theirs->post($leds . '/remove', ['outcome' => 'sold', 'sold_price' => '9999']);
        self::assertSame(200, $sale->getStatusCode(), self::body($sale));
        $gone = ApiClient::json($this->api->get($leds));
        self::assertNull($gone->get('fittings', 0, 'sold_price'), 'a sale they record has no price, not 0 (K5)');

        $theirId = ApiClient::json($own)->int('entry', 'id');
        $edit = $theirs->patch($this->mods . '/' . $theirId, ['cost' => '900', 'title' => 'Their lights, fitted']);
        self::assertSame(200, $edit->getStatusCode());
        self::assertSame('0.000', ApiClient::json($this->api->get($this->mods . '/' . $theirId))->get('cost'));
        self::assertSame(403, $theirs->get($this->mods . '/' . $id . '/attachments')->getStatusCode(), 'files are cost files');
    }

    public function testAccessFollowsEntryAccessAndViewIsReadOnly(): void
    {
        $id = $this->logged();
        $member = $this->createMember($this->app, 'logger');
        $now = new DateTimeImmutable(self::NOW);
        $shares = $this->service($this->app, VehicleShareRepository::class);
        $shares->insert($this->golf->id, $member->id, ShareLevel::Log, true, false, $now);
        $theirs = $this->api($this->app, $this->apiKey($this->app, $member));

        self::assertSame(200, $theirs->get($this->mods . '/' . $id)->getStatusCode());
        $mine = $theirs->patch($this->mods . '/' . $id, ['title' => 'Mine now']);
        self::assertSame(403, $mine->getStatusCode(), 'someone else\'s');
        self::assertSame(403, $theirs->delete($this->mods . '/' . $id)->getStatusCode());
        $undo = $theirs->post($this->mods . '/' . $id . '/undo-revert', []);
        self::assertSame(403, $undo->getStatusCode(), 'undo follows EntryAccess');
        self::assertSame(200, $theirs->post($this->mods . '/' . $id . '/revert', [])->getStatusCode(), 'Log reverts any');
        $remap = self::mod(['title' => 'Their remap', 'parts_fitted' => [], 'parts_removed' => []]);
        $own = ApiClient::json($theirs->post($this->mods, $remap))->int('entry', 'id');
        self::assertSame(200, $theirs->patch($this->mods . '/' . $own, ['title' => 'Their remap 2'])->getStatusCode());
        self::assertSame(200, $theirs->post($this->mods . '/' . $own . '/revert', [])->getStatusCode());
        self::assertSame(200, $theirs->post($this->mods . '/' . $own . '/undo-revert', [])->getStatusCode(), 'their own');

        $viewer = $this->createMember($this->app, 'viewer');
        $shares->insert($this->golf->id, $viewer->id, ShareLevel::View, false, false, $now);
        $looking = $this->api($this->app, $this->apiKey($this->app, $viewer));
        self::assertSame(200, $looking->get($this->mods)->getStatusCode());
        self::assertSame(200, $looking->get($this->parts)->getStatusCode());
        self::assertSame(403, $looking->post($this->mods, self::mod(['title' => 'Nope']))->getStatusCode());
        self::assertSame(403, $looking->post($this->parts, ['name' => 'Nope'])->getStatusCode());
        self::assertSame(403, $looking->post($this->parts . '/1/fit', [])->getStatusCode());
        self::assertSame(204, $this->api->delete($this->mods . '/' . $id)->getStatusCode());
        $readings = $this->service($this->app, OdometerReadingRepository::class);
        self::assertNull($readings->findByEntry($this->golf->id, OdometerSource::Modification, $id));
    }

    public function testFilesAttachToAModificationAndAPart(): void
    {
        $id = $this->logged();
        $path = $this->mods . '/' . $id . '/attachments';
        $uploaded = $this->api->upload($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 'invoice.pdf');
        self::assertSame(201, $uploaded->getStatusCode(), self::body($uploaded));
        self::assertCount(1, ApiClient::json($this->api->get($path))->doc('items')->toArray());
        $part = $this->part();
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $upload = $this->api->upload($this->parts . '/' . $part . '/attachments', $pdf, 'warranty.pdf');
        self::assertSame(201, $upload->getStatusCode(), self::body($upload));
    }

    public function testHistoryCarriesTheNewKindsAndLinksToThem(): void
    {
        $id = $this->logged();
        $part = $this->part();
        $this->api->post($this->parts . '/' . $part . '/fit', ['fitted_on' => '2026-06-01']);

        $path = '/vehicles/' . $this->golf->id . '/history?kinds=modification,part_fitted';
        $items = ApiClient::json($this->api->get($path));
        $kinds = $items->column('kind', 'items');
        self::assertContains('modification', $kinds);
        self::assertContains('part_fitted', $kinds);
        $base = '/vehicles/' . $this->golf->id;
        $at = array_search('modification', $kinds, true);
        self::assertIsInt($at);
        self::assertSame($base . '/modifications/' . $id, $items->get('items', $at, 'links', 'entry'));
        self::assertSame('899.000', $items->get('items', $at, 'amount'));
        $links = [];
        foreach (array_keys($kinds) as $i) {
            $links[] = $items->get('items', $i, 'links', 'entry');
        }
        self::assertContains($base . '/parts/' . $part, $links, 'a part fitted without a record');
    }

    public function testAnArchivedVehicleTakesNoWritesAndTheModuleOffIs404(): void
    {
        $id = $this->logged();
        $part = $this->part();
        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);
        self::assertSame(409, $this->api->post($this->mods, self::mod(['title' => 'Later']))->getStatusCode());
        self::assertSame(409, $this->api->post($this->parts, ['name' => 'Later'])->getStatusCode());
        self::assertSame(409, $this->api->post($this->mods . '/' . $id . '/revert', [])->getStatusCode());
        self::assertSame(409, $this->api->post($this->parts . '/' . $part . '/fit', [])->getStatusCode());
        self::assertSame(200, $this->api->get($this->mods . '/' . $id)->getStatusCode());

        $without = array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Parts));
        $this->service($this->app, FeatureToggles::class)->save($without);
        self::assertSame(404, $this->api->get($this->mods)->getStatusCode());
        self::assertSame(404, $this->api->get($this->parts . '/' . $part)->getStatusCode());
        self::assertSame(404, $this->api->post($this->parts, ['name' => 'Nope'])->getStatusCode());
    }
}
