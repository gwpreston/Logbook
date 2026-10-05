<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Mcp;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Ai\Draft\DraftSource;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Ai\Provider\ToolCall;
use Logbook\Support\Display\UserDisplayScope;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\JsonDoc;
use Logbook\Tests\Support\McpClient;
use Logbook\Tests\Support\Migrator;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The tools over MCP (spec.md §7.28 *Tools*): the Ask read tools with the
 * same results as Ask gets, for what the key's user sees; for read-and-write
 * keys, fill-ups and readings written through the API's write path (never
 * twice), and the other kinds kept as drafts until the user adds them.
 */
final class McpToolsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const array READ_TOOLS = [
        'find_vehicles', 'costs', 'cost_per_distance', 'maintenance', 'vehicle_summary', 'fuel_stats', 'last_done',
        'mileage', 'ownership', 'true_cost', 'coming_up', 'documents', 'tyres', 'incidents', 'needs_attention', 'finance',
        'stations',
    ];
    private const array WRITE_TOOLS = [
        'log_fill_up', 'add_reading', 'draft_service_record', 'draft_document', 'draft_expense', 'draft_tyre_check',
        'draft_reminder', 'draft_incident',
    ];

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testEveryReadToolGivesAsksFiguresForTheDemoDataAndEachUser(): void
    {
        $app = $this->createApp(['APP_URL' => 'https://cars.example']);
        $this->resetDatabase($app);
        Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        $users = $this->service($app, UserRepository::class);

        foreach (['demo', 'partner'] as $username) {
            $user = $users->findByUsername($username);
            self::assertInstanceOf(User::class, $user);
            $mcp = new McpClient($app, $this->apiKey($app, $user, ApiScope::Read));
            $vehicles = json_decode(
                McpClient::result($mcp->modern('resources/read', ['uri' => 'logbook://vehicles']))->string('contents', 0, 'text'),
                true,
            );
            self::assertIsArray($vehicles);
            self::assertNotEmpty($vehicles, $username);
            $vehicles = new JsonDoc($vehicles);

            $calls = [
                ['find_vehicles', ['query' => $vehicles->get(0, 'name')]],
                ['costs', ['period' => 'all_time', 'group_by' => 'vehicle']],
                ['costs', ['period' => 'last_12_months', 'group_by' => 'month']],
                ['cost_per_distance', ['period' => 'all_time']],
                ['fuel_stats', ['period' => 'all_time']],
                ['mileage', ['period' => 'this_year']],
                ['coming_up', []],
                ['needs_attention', []],
                ['documents', []],
            ];
            foreach ($vehicles->keys() as $index) {
                $id = $vehicles->int($index, 'id');
                foreach (['vehicle_summary', 'maintenance', 'fuel_stats', 'tyres', 'ownership', 'documents'] as $tool) {
                    $calls[] = [$tool, ['vehicle' => $id]];
                }
                $calls[] = ['last_done', ['vehicle' => $id, 'category' => 'service']];
            }

            foreach ($calls as [$tool, $arguments]) {
                $label = $username . ' ' . $tool . ' ' . json_encode($arguments);
                $run = $this->service($app, UserDisplayScope::class)->run(
                    $user,
                    fn () => $this->service($app, ToolRegistry::class)->run($user, new ToolCall('t', $tool, $arguments)),
                );
                $response = $mcp->modern('tools/call', ['name' => $tool, 'arguments' => $arguments]);
                if ($run->result === null) {
                    self::assertSame(['error' => $run->error], McpClient::structured($response, true)->toArray(), $label);
                    continue;
                }
                $data = McpClient::structured($response)->toArray();
                $link = $data['link'] ?? null;
                unset($data['link']);
                self::assertSame(
                    json_decode((string) json_encode($run->result->data), true),
                    $data,
                    $label,
                );
                self::assertSame($run->result->link === null ? null : 'https://cars.example' . $run->result->link, $link, $label);
            }
        }
    }

    public function testAKeyNeverSeesMoreThanItsUser(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $bmw = $this->vehicle($app, 'BMW', '320i');
        $this->fillUp($app, $bmw, '2026-09-01T08:00:00Z', '10000', '40', '61.20');
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-01'));
        $this->service($app, VehicleAccess::class)->forget();
        $mcp = new McpClient($app, $this->apiKey($app, $member, ApiScope::ReadWrite));

        $found = McpClient::structured($mcp->modern('tools/call', [
            'name' => 'find_vehicles',
            'arguments' => ['query' => 'Golf'],
        ]));
        self::assertSame([$golf->id], $found->column('id', 'vehicles'));
        $none = McpClient::structured($mcp->modern('tools/call', ['name' => 'find_vehicles', 'arguments' => ['query' => 'BMW']]));
        self::assertSame(0, $none->get('count'), 'the owner\'s BMW is not theirs');

        $hidden = $mcp->modern('tools/call', ['name' => 'vehicle_summary', 'arguments' => ['vehicle' => $bmw->id]]);
        self::assertTrue(McpClient::result($hidden)->get('isError'));
        self::assertStringNotContainsString('61.20', (string) $hidden->getBody());

        $costs = $mcp->modern('tools/call', ['name' => 'costs', 'arguments' => ['period' => 'all_time']]);
        self::assertStringNotContainsString('61.20', (string) $costs->getBody(), 'the BMW is not theirs');

        $summary = $mcp->modern('resources/read', ['uri' => 'logbook://vehicles/' . $bmw->id . '/summary']);
        self::assertSame(-32602, McpClient::body($summary)->get('error', 'code'));

        $names = McpClient::result($mcp->modern('tools/list'))->column('name', 'tools');
        self::assertNotContains('log_fill_up', $names, 'View on the Golf: nothing to log to');
        self::assertNotContains('draft_reminder', $names);
    }

    public function testToolsFollowTheKeysScopeAndTheModulesButNotTheAiSwitches(): void
    {
        $app = $this->createApp(['FEATURES_AI_ASK' => 'false', 'FEATURES_AI_ACTIONS' => 'false', 'AI_ENABLED' => 'false']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $this->vehicle($app);

        $read = self::names(McpClient::result((new McpClient($app, $this->apiKey($app, $owner, ApiScope::Read)))
            ->modern('tools/list')));
        $write = McpClient::result((new McpClient($app, $this->apiKey($app, $owner, ApiScope::ReadWrite)))
            ->modern('tools/list'));
        $writeNames = self::names($write);

        foreach (self::READ_TOOLS as $tool) {
            self::assertContains($tool, $read);
        }
        self::assertSame([], array_values(array_intersect(self::WRITE_TOOLS, $read)), 'a read key never sees a write');
        self::assertSame(self::WRITE_TOOLS, array_values(array_intersect($writeNames, self::WRITE_TOOLS)));
        self::assertSame($writeNames, self::names(McpClient::result((new McpClient($app, $this->apiKey($app, $owner)))
            ->modern('tools/list'))), 'the same order every time');
        $byName = new JsonDoc(array_column($write->doc('tools')->toArray(), null, 'name'));
        self::assertTrue($byName->get('costs', 'annotations', 'readOnlyHint'));
        self::assertTrue($byName->get('log_fill_up', 'annotations', 'idempotentHint'));
        self::assertFalse($byName->get('draft_expense', 'annotations', 'readOnlyHint'));
        self::assertStringNotContainsString('card', $byName->string('log_fill_up', 'description'));

        $noFuel = $this->createApp(['FEATURES_FUEL' => 'false']);
        $names = McpClient::result((new McpClient($noFuel, $this->apiKey($noFuel, $owner)))
            ->modern('tools/list'))->column('name', 'tools');
        self::assertNotContains('fuel_stats', $names);
        self::assertNotContains('stations', $names, 'stations are part of fuel');
        self::assertNotContains('log_fill_up', $names);
        self::assertContains('add_reading', $names);
    }

    public function testAReadKeyCannotCallAWriteTool(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $mcp = new McpClient($app, $this->apiKey($app, $owner, ApiScope::Read));

        foreach (self::WRITE_TOOLS as $tool) {
            $response = $mcp->modern('tools/call', [
                'name' => $tool,
                'arguments' => ['vehicle' => $golf->id, 'odometer' => '12000'],
            ]);
            self::assertSame(-32602, McpClient::body($response)->get('error', 'code'), $tool);
        }
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM odometer_readings'));
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM ai_drafts'));
    }

    public function testLogFillUpWritesOnceAndARetryIsADuplicate(): void
    {
        $app = $this->createApp(['APP_URL' => 'https://cars.example']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '10000', '40', '60.00');
        $mcp = new McpClient($app, $this->apiKey($app, $owner));
        $arguments = [
            'vehicle' => $golf->id,
            'date' => '2026-09-20',
            'odometer' => '10600',
            'volume' => '42.5',
            'total_cost' => '63.75',
        ];

        $logged = McpClient::structured($mcp->modern('tools/call', ['name' => 'log_fill_up', 'arguments' => $arguments]));
        self::assertSame('logged', $logged->get('status'));
        self::assertSame('https://cars.example/vehicles/' . $golf->id . '/fuel', $logged->get('link'));
        self::assertStringContainsString($logged->string('link'), $logged->string('say'));
        self::assertIsInt($logged->get('entry_id'));

        $again = McpClient::structured($mcp->legacy('tools/call', ['name' => 'log_fill_up', 'arguments' => $arguments]));
        self::assertSame('duplicate', $again->get('status'), 'a retry, even from the other era, logs nothing new');
        self::assertSame($logged->get('link'), $again->get('link'), 'and says where it is');
        self::assertFalse(McpClient::result($mcp->modern('tools/call', ['name' => 'log_fill_up', 'arguments' => $arguments]))
            ->get('isError'));

        self::assertEquals(2, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM fuel_entries'));
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM ai_drafts'), 'never a draft');

        $reading = McpClient::structured($mcp->modern('tools/call', ['name' => 'add_reading', 'arguments' => [
            'vehicle' => $golf->id,
            'odometer' => '10750',
        ]]));
        self::assertSame('logged', $reading->get('status'));
        self::assertEquals(1, $this->connection($app)->fetchOne(
            'SELECT COUNT(*) FROM odometer_readings WHERE source = ?',
            ['manual'],
        ));

        $invalid = $mcp->modern('tools/call', [
            'name' => 'log_fill_up',
            'arguments' => ['vehicle' => $golf->id, 'odometer' => 'lots'],
        ]);
        $answer = McpClient::structured($invalid, false);
        self::assertContains($answer->get('status'), ['ask_user', 'needs', 'invalid']);
        self::assertNotSame('', $answer->string('say'));
    }

    public function testADraftWaitsSevenDaysForTheUsersAdd(): void
    {
        $app = $this->createApp(['APP_URL' => 'https://cars.example']);
        $clock = $this->pinClock($app, '2026-10-01T09:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $mcp = new McpClient($app, $this->apiKey($app, $owner));

        $saved = McpClient::structured($mcp->modern('tools/call', ['name' => 'draft_service_record', 'arguments' => [
            'vehicle' => $golf->id,
            'category' => 'service',
            'title' => 'Annual service',
            'date' => '2026-09-30',
            'cost' => '189.00',
            'odometer' => '12000',
        ]]));
        self::assertSame('draft_saved', $saved->get('status'));
        self::assertSame('https://cars.example/#draft-' . $saved->int('draft_id'), $saved->get('link'));
        self::assertSame(
            'Draft saved. Open https://cars.example/#draft-' . $saved->int('draft_id') . ' to add it.',
            $saved->get('say'),
        );
        self::assertEquals(
            0,
            $this->connection($app)->fetchOne('SELECT COUNT(*) FROM maintenance_entries'),
            'nothing written yet',
        );

        $draft = $this->service($app, DraftStore::class)->get($owner, $saved->int('draft_id'));
        self::assertSame(DraftSource::Mcp, $draft->source);
        self::assertNull($draft->threadId);
        self::assertSame('2026-10-08T09:00:00+00:00', $draft->expiresAt->format(DATE_ATOM));

        $browser = $this->browserFor($app, 'owner');
        $home = (string) $browser->get('/')->getBody();
        self::assertStringContainsString('id="drafts-to-review"', $home);
        self::assertStringContainsString('/drafts/' . $draft->id . '/add', $home);
        self::assertStringContainsString('Drafted by an assistant (MCP)', $home);

        $added = $browser->post('/drafts/' . $draft->id . '/add', ['back' => 'home']);
        self::assertSame(303, $added->getStatusCode());
        self::assertStringEndsWith('/#drafts-to-review', $added->getHeaderLine('Location'));
        self::assertEquals(1, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM maintenance_entries'));
        $justAdded = (string) $browser->get('/')->getBody();
        self::assertStringContainsString('/drafts/' . $draft->id . '/undo', $justAdded, 'Undo, for a few seconds');
        $clock->set($clock->now()->modify('+11 seconds'));
        self::assertStringNotContainsString('id="drafts-to-review"', (string) $browser->get('/')->getBody());

        $late = McpClient::structured($mcp->modern('tools/call', ['name' => 'draft_expense', 'arguments' => [
            'vehicle' => $golf->id,
            'category' => 'parking',
            'amount' => '4.50',
        ]]));
        $clock->set($clock->now()->modify('+7 days'));
        self::assertStringNotContainsString('id="drafts-to-review"', (string) $browser->get('/')->getBody(), 'expired');
        $browser->post('/drafts/' . $late->int('draft_id') . '/add', ['back' => 'home']);
        self::assertEquals(
            0,
            $this->connection($app)->fetchOne('SELECT COUNT(*) FROM expense_entries'),
            'an expired draft never adds',
        );
        // The expired expense, and the service record added a week ago (kept a day after Add).
        self::assertSame(2, $this->service($app, DraftStore::class)->deleteExpired());
    }

    public function testDraftsAreReviewedWithoutAiAndAskDraftsStayOnAsk(): void
    {
        $app = $this->createApp(['AI_ENABLED' => 'false']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $mcp = new McpClient($app, $this->apiKey($app, $owner));
        $saved = McpClient::structured($mcp->modern('tools/call', ['name' => 'draft_reminder', 'arguments' => [
            'vehicle' => $golf->id,
            'title' => 'Wash the car',
            'due' => '2026-12-01',
        ]]));
        $browser = $this->browserFor($app, 'owner');

        $discarded = $browser->post('/drafts/' . $saved->int('draft_id') . '/discard', ['back' => 'ask']);
        self::assertSame(303, $discarded->getStatusCode());
        self::assertStringEndsWith('/#drafts-to-review', $discarded->getHeaderLine('Location'), 'no Ask: back to the dashboard');
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM reminders'));

        $this->createMember($app);
        $other = $this->browserFor($app, 'partner');
        $theirs = $other->post('/drafts/' . $saved->int('draft_id') . '/add', []);
        self::assertSame(404, $theirs->getStatusCode(), 'another user\'s draft');
    }

    /**
     * The tools' names, in order.
     *
     * @return list<string>
     */
    private static function names(JsonDoc $list): array
    {
        return array_map(static function (mixed $name): string {
            self::assertIsString($name);

            return $name;
        }, $list->column('name', 'tools'));
    }
}
