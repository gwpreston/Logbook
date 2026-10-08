<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\JsonDoc;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Tyre changes over the API (Phase 39.2, spec.md §7.17, §7.20): every kind
 * replayed through the change form, refused (422) where the form refuses;
 * an edit changes the date, odometer, note and link only; a delete replays
 * the rest and is refused (409) where the page refuses it; a tyre's own
 * details need Manage.
 */
final class ApiTyreWritesTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $this->base = '/vehicles/' . $this->golf->id . '/tyres';
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function change(array $body, int $status = 201): JsonDoc
    {
        $response = $this->api->post($this->base . '/changes', $body + ['distance_unit' => 'km', 'depth_unit' => 'mm']);
        self::assertSame($status, $response->getStatusCode(), self::body($response));

        return ApiClient::json($response);
    }

    /**
     * Tyre id by position, from `GET …/tyres`.
     *
     * @return array<string, int>
     */
    private function fitted(): array
    {
        $tyres = ApiClient::json($this->api->get($this->base));
        $items = $tyres->get('items');
        self::assertIsArray($items);
        $byPosition = [];
        foreach (array_keys($items) as $i) {
            $position = $tyres->get('items', $i, 'position');
            if (is_string($position) && $tyres->get('items', $i, 'status') === 'fitted') {
                $byPosition[$position] = $tyres->int('items', $i, 'id');
            }
        }
        ksort($byPosition);

        return $byPosition;
    }

    private function existing(): int
    {
        $tyres = array_map(static fn (string $p): array => [
            'position' => $p, 'brand' => 'Goodyear', 'model' => 'EfficientGrip', 'size' => '205/55 R16 91V', 'tread' => '8',
        ], ['fl', 'fr', 'rl', 'rr']);

        return $this->change(['kind' => 'existing', 'changed_on' => '2025-10-03', 'odometer' => 20000, 'tyres' => $tyres])
            ->int('entry', 'id');
    }

    public function testEveryKindIsReplayedThroughTheChangeForm(): void
    {
        $existing = $this->existing();
        $fitted = $this->fitted();
        self::assertSame(['fl', 'fr', 'rl', 'rr'], array_keys($fitted));

        $fit = $this->change([
            'kind' => 'fit', 'changed_on' => '2025-11-01', 'odometer' => 21000,
            'tyre' => ['brand' => 'Michelin', 'model' => 'Primacy 4', 'size' => '205/55 R16 91V', 'season' => 'summer'],
            'tread' => '8',
            'positions' => [['position' => 'fl', 'dot' => '2325', 'replace' => 'worn']],
        ]);
        self::assertSame('fit', $fit->get('entry', 'kind'));
        $afterFit = $this->fitted();
        self::assertNotSame($fitted['fl'], $afterFit['fl'], 'a new tyre at fl');

        $moves = [$afterFit['fl'] => 'fr', $afterFit['fr'] => 'fl', $afterFit['rl'] => 'rl', $afterFit['rr'] => 'rr'];
        $this->change(['kind' => 'rotate', 'changed_on' => '2026-01-10', 'odometer' => 23000, 'moves' => $moves]);
        self::assertSame($afterFit['fl'], $this->fitted()['fr'], 'rotated');

        $this->change(['kind' => 'repair', 'changed_on' => '2026-02-01', 'odometer' => 24000, 'tyres' => [$afterFit['rl']]]);

        $removed = $this->change([
            'kind' => 'remove', 'changed_on' => '2026-03-01', 'odometer' => 25000,
            'set' => ['name' => 'Spares', 'storage' => 'Shed'],
            'tyres' => [$afterFit['rl'] => 'store', $afterFit['rr'] => 'store'],
            'depths' => [$afterFit['rl'] => '5.5'],
        ]);
        self::assertSame(['off', 'off'], $removed->column('action', 'entry', 'lines'));
        $sets = ApiClient::json($this->api->get('/vehicles/' . $this->golf->id . '/tyre-sets'));
        self::assertSame(['Spares'], $sets->column('name', 'items'));
        $setId = $sets->int('items', 0, 'id');

        $this->change([
            'kind' => 'swap', 'changed_on' => '2026-04-01', 'odometer' => 26000, 'set' => $setId,
            'on' => [$afterFit['rl'] => 'rl', $afterFit['rr'] => 'rr'],
        ]);
        self::assertSame($afterFit['rl'], $this->fitted()['rl'], 'back on from the set');

        $list = ApiClient::json($this->api->get($this->base . '/changes'));
        self::assertSame(['swap', 'remove', 'repair', 'rotate', 'fit', 'existing'], $list->column('kind', 'items'));
        self::assertSame($existing, $list->int('items', 5, 'id'));
    }

    public function testWhatTheFormRefusesIsRefused(): void
    {
        $this->existing();
        $fitted = $this->fitted();

        $none = $this->change(['kind' => 'fit', 'odometer' => 21000, 'tyre' => ['brand' => 'Michelin'], 'positions' => []], 422);
        self::assertSame('tyre.error.positions', $none->get('errors', 'positions', 'key'));
        $replace = $this->change([
            'kind' => 'fit', 'odometer' => 21000, 'tyre' => ['brand' => 'Michelin'], 'positions' => [['position' => 'fl']],
        ], 422);
        $key = $replace->get('errors', 'positions.0.replace', 'key');
        self::assertSame('validation.required', $key, 'the fitted tyre must go somewhere');
        $dot = $this->change([
            'kind' => 'fit', 'odometer' => 21000, 'tyre' => ['brand' => 'Michelin'],
            'positions' => [['position' => 'fl', 'dot' => '9925', 'replace' => 'store']],
        ], 422);
        self::assertSame('tyre.error.dot_week', $dot->get('errors', 'positions.0.dot', 'key'));
        $moves = [$fitted['fl'] => 'fr', $fitted['fr'] => 'fl', $fitted['rl'] => 'rl'];
        $missing = $this->change(['kind' => 'rotate', 'odometer' => 21000, 'moves' => $moves], 422);
        self::assertSame('validation.required', $missing->get('errors', 'moves.' . $fitted['rr'], 'key'));
        $unknown = $this->change(['kind' => 'rotate', 'odometer' => 21000, 'tyre' => []], 422);
        self::assertSame('api.validation.unknown_field', $unknown->get('errors', 'tyre', 'key'));
        self::assertSame('validation.required', $this->change(['odometer' => 1], 422)->get('errors', 'kind', 'key'));
        self::assertSame('validation.choice', $this->change(['kind' => 'check'], 422)->get('errors', 'kind', 'key'));
        $backwards = $this->change(
            ['kind' => 'repair', 'changed_on' => '2025-09-01', 'odometer' => 19000, 'tyres' => [$fitted['fl']]],
            422,
        );
        self::assertSame('tyre.error.sequence.not_fitted', $backwards->get('errors', 'form', 'key'), 'the replay refuses');
    }

    public function testAnEditChangesWhatThePageEditsAndADeleteReplays(): void
    {
        $existing = $this->existing();
        $fitted = $this->fitted();
        $moves = [$fitted['fl'] => 'fr', $fitted['fr'] => 'fl', $fitted['rl'] => 'rl', $fitted['rr'] => 'rr'];
        $rotate = $this->change(['kind' => 'rotate', 'changed_on' => '2026-01-10', 'odometer' => 23000, 'moves' => $moves])
            ->int('entry', 'id');
        $path = $this->base . '/changes/' . $rotate;

        $edited = $this->api->patch($path, ['note' => 'At the tyre shop', 'odometer' => '23100', 'distance_unit' => 'km']);
        self::assertSame(200, $edited->getStatusCode(), self::body($edited));
        self::assertSame('At the tyre shop', ApiClient::json($edited)->get('entry', 'note'));
        self::assertSame('2026-01-10', ApiClient::json($edited)->get('entry', 'changed_on'), 'unsent fields stay');
        $tag = $edited->getHeaderLine('ETag');
        self::assertSame(412, $this->api->patch($path, ['note' => 'x'], ['If-Match' => '"stale"'])->getStatusCode());
        self::assertSame(422, $this->api->patch($path, ['moves' => []])->getStatusCode(), 'only what the page edits');
        self::assertSame(200, $this->api->patch($path, ['note' => null], ['If-Match' => $tag])->getStatusCode());

        $refused = $this->api->delete($this->base . '/changes/' . $existing);
        self::assertSame(409, $refused->getStatusCode(), 'a later rotation needs those tyres on');
        self::assertSame('tyre_change_refused', ApiClient::json($refused)->get('code'));
        self::assertSame(204, $this->api->delete($path)->getStatusCode());
        $deleted = $this->api->delete($this->base . '/changes/' . $existing);
        self::assertSame(204, $deleted->getStatusCode(), 'nothing depends on it now');
        self::assertSame([], $this->fitted());
    }

    public function testATyresOwnDetailsNeedManage(): void
    {
        $this->existing();
        $fl = $this->fitted()['fl'];
        $path = $this->base . '/' . $fl;

        $edited = $this->api->patch($path, ['brand' => 'Goodyear', 'dot' => '1225', 'notes' => 'Checked']);
        self::assertSame(200, $edited->getStatusCode(), self::body($edited));
        self::assertSame('Checked', ApiClient::json($edited)->get('entry', 'notes'));
        self::assertSame('EfficientGrip', ApiClient::json($edited)->get('entry', 'model'), 'unsent fields stay');
        self::assertSame(422, $this->api->patch($path, ['position' => 'fr'])->getStatusCode(), 'position comes from changes');

        $logger = $this->createMember($this->app, 'logger');
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $logger->id, ShareLevel::Log, true, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $theirs = $this->api($this->app, $this->apiKey($this->app, $logger));
        self::assertSame(403, $theirs->patch($path, ['notes' => 'x'])->getStatusCode());
        $change = ApiClient::json($this->api->get($this->base . '/changes'))->int('items', 0, 'id');
        self::assertSame(403, $theirs->delete($this->base . '/changes/' . $change)->getStatusCode(), 'someone else\'s change');
    }
}
