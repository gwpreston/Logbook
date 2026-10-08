<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Job\JobRun;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Webhook\Webhook;
use Logbook\Domain\Webhook\WebhookDelivery;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Domain\Webhook\WebhookPause;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Repository\WebhookDeliveryRepository;
use Logbook\Repository\WebhookRepository;
use Logbook\Service\Ai\Draft\DraftWriter;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Jobs\WebhooksJob;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Webhook\WebhookSender;
use Logbook\Service\Webhook\WebhookService;
use Logbook\Domain\Trip\TripData;
use Logbook\Service\Access\AccessContext;
use cebe\openapi\Reader;
use cebe\openapi\spec\Schema;
use League\OpenAPIValidation\Schema\SchemaValidator;
use Logbook\Kernel;
use Logbook\Support\Api\OpenApiDocument;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\JsonDoc;
use Psr\Http\Message\ResponseInterface;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Entry webhooks (Phase 39.3, spec.md §7.20 *Webhooks*, #285, #288–#295,
 * #301, #302): every change, by every path, queues a delivery of ids and
 * links for everyone who may see it; the `webhooks` job signs and sends it
 * to an address §7.11's policy allows, retries, pauses after 50 failed
 * attempts in a row and tells the user once; the Settings page manages
 * them without JS; backups carry them without their secret.
 */
final class WebhooksTest extends ReminderTestCase
{
    use ApiFixtures;

    private const string NOW = '2026-10-08T09:00:00Z';
    private const string HOOK = 'https://hooks.test/entries';

    /** @var App<ContainerInterface> */
    private App $app;
    private MutableClock $clock;
    private TestBrowser $browser;
    private User $owner;
    private Vehicle $golf;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createRecordingApp(['FEATURES_TRIPS' => 'true'] + self::NO_CHANNELS);
        $this->clock = $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->owner = $this->owner($this->app);
        $this->golf = $this->vehicle($this->app);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        self::clearThrottle();
        // *Send test* shares the channel tests' allowance, counted per user id, which every test reuses.
        foreach (glob(Kernel::rootDir() . '/var/cache/rate-limit/*.json') ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testEveryPathQueuesIdsAndLinksOnly(): void
    {
        $webhook = $this->webhook($this->owner);
        $base = '/vehicles/' . $this->golf->id;

        // A form.
        $this->browser->get($base . '/expenses/new');
        $this->browser->post($base . '/expenses/new', [
            'category' => 'parking', 'spent_on' => '2026-10-02', 'amount' => '4.5', 'note' => 'Secret car park',
        ]);
        // The API.
        $api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $api->post($base . '/odometer', ['odometer' => '12000', 'distance_unit' => 'km']);
        // An Ask or MCP draft, written when confirmed.
        $this->service($this->app, DraftWriter::class)
            ->write($this->owner, $this->golf, DraftKind::Odometer, ['odometer' => '12500', 'distance_unit' => 'km']);
        // A CSV import.
        $map = $this->browser->post($base . '/import/expenses', [], ['file' => $this->csv("Date,Amount\n2026-01-01,7.25\n")]);
        $location = $map->getHeaderLine('Location');
        $mapping = self::body($this->browser->get($location));
        $query = Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        $preview = self::body($this->browser->get($location . '?' . http_build_query($query)));
        $form = Html::element(Html::document($preview), 'form[method="post"][action="' . $location . '"]');
        $this->browser->post($location, Html::formValues($form));

        $queued = $this->queued($webhook);
        self::assertSame(
            ['expense', 'odometer', 'odometer', 'expense'],
            array_map(static fn (WebhookDelivery $d): mixed => $d->payload['kind'], $queued),
        );
        $first = $queued[0]->payload;
        self::assertSame(
            ['event', 'id', 'occurred_at', 'vehicle_id', 'kind', 'entry_id', 'links'],
            array_keys($first),
        );
        self::assertSame('entry.created', $first['event']);
        self::assertSame($this->golf->id, $first['vehicle_id']);
        self::assertSame('2026-10-08T09:00:00Z', $first['occurred_at']);
        self::assertIsInt($first['entry_id']);
        self::assertSame([
            'entry' => $base . '/expenses/' . $first['entry_id'],
            'list' => $base . '/expenses',
            'vehicle' => $base,
        ], $first['links']);
        foreach ($queued as $delivery) {
            self::assertMatchesPayloadSchema($delivery->payload);
        }
        $all = json_encode(array_map(static fn (WebhookDelivery $d): array => $d->payload, $queued), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('4.5', $all, 'no amounts');
        self::assertStringNotContainsString('7.25', $all);
        self::assertStringNotContainsString('Secret car park', $all, 'no contents');

        // An edit and a delete; a deleted entry has no entry link.
        $id = $first['entry_id'];
        $api->patch($base . '/expenses/' . $id, ['note' => 'x']);
        $api->delete($base . '/expenses/' . $id);
        $queued = $this->queued($webhook);
        self::assertSame('entry.updated', $queued[4]->payload['event']);
        self::assertSame('entry.deleted', $queued[5]->payload['event']);
        self::assertArrayNotHasKey('entry', (array) $queued[5]->payload['links']);
    }

    public function testVehiclesTyreDetailsSchedulesAndRemindersFireToo(): void
    {
        $webhook = $this->webhook($this->owner);
        $api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $base = '/vehicles/' . $this->golf->id;

        $api->patch($base, ['nickname' => 'Blue']);
        $api->post($base . '/schedules', ['title' => 'Oil', 'category' => 'service', 'interval_months' => 12]);
        $reminder = \Logbook\Tests\Support\ApiClient::json($api->post($base . '/reminders', [
            'title' => 'Wash it', 'due_on' => '2026-12-01',
        ]))->int('entry', 'id');
        $api->post('/reminders/' . $reminder . '/dismiss', []);

        foreach ($this->queued($webhook) as $delivery) {
            self::assertMatchesPayloadSchema($delivery->payload);
        }
        $kinds = array_map(
            static fn (WebhookDelivery $d): string => self::text($d->payload['kind'] ?? null)
                . ':' . self::text($d->payload['change'] ?? $d->payload['event'] ?? null),
            $this->queued($webhook),
        );
        self::assertContains('vehicle:entry.updated', $kinds);
        self::assertContains('schedule:entry.created', $kinds);
        self::assertContains('reminder:created', $kinds);
        self::assertContains('reminder:dismissed', $kinds);
    }

    public function testEachKindNamesWhatToFetch(): void
    {
        $webhook = $this->webhook($this->owner);
        $api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $base = '/vehicles/' . $this->golf->id;
        $json = static fn (ResponseInterface $response): JsonDoc => \Logbook\Tests\Support\ApiClient::json($response);

        $change = $json($api->post($base . '/tyres/changes', ['kind' => 'existing', 'odometer' => 10000, 'tyres' => [
            ['position' => 'fl', 'brand' => 'Goodyear'],
        ]]))->int('entry', 'id');
        $tyre = $json($api->get($base . '/tyres'))->int('items', 0, 'id');
        $api->patch($base . '/tyres/' . $tyre, ['brand' => 'Michelin']);
        $checked = $api->post($base . '/tyres/checks', [
            'checked_on' => '2026-10-08', 'odometer' => '10500', 'depth_unit' => 'in32', 'depths' => ['fl' => '8'],
        ]);
        $check = $json($checked)->int('entry', 'id');
        $agreement = $json($api->post($base . '/finance/agreements', [
            'type' => 'hp', 'lender' => 'Black Horse', 'started_on' => '2024-01-15', 'first_payment_on' => '2024-02-15',
            'number_of_payments' => 48, 'regular_payment' => '301.35', 'cash_price' => 15000,
        ]))->int('entry', 'id');
        $api->post($base . '/finance/agreements/' . $agreement . '/payments', ['kind' => 'missed', 'due_on' => '2026-08-15']);
        $valuation = $json($api->post($base . '/valuations', ['amount' => 9000]))->int('entry', 'id');
        $incident = $json($api->post($base . '/incidents', [
            'occurred_on' => '2026-09-14', 'type' => 'parked_damage', 'fault' => 'not_at_fault', 'damage_areas' => ['rear'],
        ]))->int('entry', 'id');
        $api->post($base . '/archive', []);
        $api->post($base . '/restore', []);

        $seen = array_map(
            static fn (WebhookDelivery $d): string => self::text($d->payload['event'] ?? null) . ' '
                . self::text($d->payload['kind'] ?? null) . ' '
                . (is_int($d->payload['entry_id'] ?? null) ? $d->payload['entry_id'] : ''),
            $this->queued($webhook),
        );
        foreach (
            [
            'entry.created tyre ' . $change,
            'entry.updated tyre_details ' . $tyre,
            'entry.created tread_check ' . $check,
            'entry.created finance ' . $agreement,
            'entry.updated finance ' . $agreement,
            'entry.created valuation ' . $valuation,
            'entry.created incident ' . $incident,
            'entry.updated vehicle ' . $this->golf->id,
            ] as $expected
        ) {
            self::assertContains($expected, $seen);
        }
        self::assertSame(2, count(array_keys($seen, 'entry.updated vehicle ' . $this->golf->id, true)), 'archive and restore');
        foreach ($this->queued($webhook) as $delivery) {
            self::assertMatchesPayloadSchema($delivery->payload);
        }
    }

    public function testAReminderBecomingDueIsToldOnce(): void
    {
        $webhook = $this->webhook($this->owner, events: [WebhookEvent::ReminderChanged]);
        $api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $api->post('/vehicles/' . $this->golf->id . '/reminders', ['title' => 'Wash it', 'due_on' => '2026-12-01']);
        $this->clock->set(new DateTimeImmutable('2026-12-02T09:00:00Z'));
        $reminders = $this->service($this->app, \Logbook\Service\Jobs\RemindersJob::class);
        $this->service($this->app, JobRunner::class)->run($reminders, JobTrigger::Manual);
        $this->service($this->app, JobRunner::class)->run($reminders, JobTrigger::Manual);

        $changes = array_map(
            static fn (WebhookDelivery $d): string => self::text($d->payload['change'] ?? null),
            $this->queued($webhook),
        );
        self::assertSame(['created', 'overdue'], $changes);
    }

    public function testWhoHearsOfWhat(): void
    {
        $member = $this->member('driver', ShareLevel::Log, costs: false);
        $stranger = $this->member('stranger', null);
        $theirs = $this->webhook($member, 'https://hooks.test/driver');
        $nobodys = $this->webhook($stranger, 'https://hooks.test/stranger');
        $ownWebhook = $this->webhook($this->owner);

        // A cost entry reaches a share without *Can see costs* (#295): ids and kind only.
        $api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $api->post('/vehicles/' . $this->golf->id . '/expenses', ['category' => 'parking', 'amount' => '3']);
        self::assertCount(1, $this->queued($theirs));
        self::assertSame([], $this->queued($nobodys), 'a stranger hears nothing');

        // A trip only reaches those who may see it (#302).
        $this->as($this->owner, fn () => $this->service($this->app, TripService::class)->create(
            $this->golf,
            new TripData(new DateTimeImmutable('2026-10-01'), 'Home', 'Depot', distanceKm: '12.5', purpose: 'Work'),
        ));
        self::assertCount(1, $this->queued($theirs), 'not the owner\'s trip');
        self::assertSame('trip', $this->queued($ownWebhook)[1]->payload['kind']);
        $this->as($member, fn () => $this->service($this->app, TripService::class)->create(
            $this->golf,
            new TripData(new DateTimeImmutable('2026-10-02'), 'Home', 'Client', distanceKm: '20', purpose: 'Work'),
        ));
        self::assertCount(2, $this->queued($theirs), 'their own trip');
        self::assertCount(3, $this->queued($ownWebhook), 'the owner sees everyone\'s');

        // Only the events a webhook takes.
        $created = $this->webhook($this->owner, 'https://hooks.test/created', [WebhookEvent::EntryCreated]);
        $id = \Logbook\Tests\Support\ApiClient::json(
            $api->post('/vehicles/' . $this->golf->id . '/odometer', ['odometer' => '9000', 'distance_unit' => 'km']),
        )->int('entry', 'id');
        $api->delete('/vehicles/' . $this->golf->id . '/odometer/' . $id);
        self::assertCount(1, $this->queued($created));

        // A disabled user's webhooks hear nothing.
        $before = count($this->queued($theirs));
        $this->service($this->app, UserRepository::class)
            ->setDisabledAt($member->id, new DateTimeImmutable(self::NOW), new DateTimeImmutable(self::NOW));
        $api->post('/vehicles/' . $this->golf->id . '/expenses', ['category' => 'parking', 'amount' => '3']);
        self::assertCount($before, $this->queued($theirs));
    }

    public function testTheJobSignsSendsAndPinsTheAddress(): void
    {
        // A member's: pinned to the address checked (an admin's own are not restricted, §7.11).
        $member = $this->member('driver', ShareLevel::Log);
        ['webhook' => $webhook, 'secret' => $secret] = $this->service($this->app, WebhookService::class)
            ->create($member, 'Home', self::HOOK, WebhookEvent::cases());
        $this->logReading('12000');

        $run = $this->runJob();
        self::assertSame(1, $run->counts['sent'] ?? null);
        $sent = $this->http->to(self::HOOK);
        self::assertCount(1, $sent);
        $request = $sent[0];
        self::assertSame(['203.0.113.12'], array_values($request['resolve']), 'pinned to the address checked');
        self::assertSame(0, $request['max_redirects']);
        self::assertSame(['entry.created'], $request['headers']['x-logbook-event'] ?? null);
        $signature = ($request['headers']['x-logbook-signature'] ?? [''])[0];
        self::assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $signature);
        self::assertSame(1, preg_match('/^t=(\d+),v1=([0-9a-f]+)$/', $signature, $m));
        [, $time, $mac] = $m + [null, '', ''];
        self::assertSame(hash_hmac('sha256', $time . '.' . $request['body'], $secret), $mac, 'the receiver can check it');
        self::assertSame((string) (new DateTimeImmutable(self::NOW))->getTimestamp(), $time);

        $delivery = $this->queued($webhook)[0];
        self::assertNull($delivery->nextAttemptAt);
        self::assertNotNull($delivery->deliveredAt);
        self::assertSame('ok', $this->stored($webhook)->lastStatus);
        $this->runJob();
        self::assertCount(1, $this->http->to(self::HOOK), 'never sent twice');
    }

    public function testEachCallIsSignedWithTheTimeItIsSent(): void
    {
        $member = $this->member('driver', ShareLevel::Log);
        $this->webhook($member);
        $this->logReading('12000');
        $this->logReading('12100');
        // Each request takes 200 seconds of the clock, as a slow receiver would.
        $clock = $this->clock;
        $times = [];
        $this->http->onRequest = static function () use ($clock, &$times): void {
            $times[] = $clock->now()->getTimestamp();
            $clock->set($clock->now()->modify('+200 seconds'));
        };

        $this->runJob();

        $sent = $this->http->to(self::HOOK);
        self::assertCount(2, $sent);
        foreach ($sent as $i => $request) {
            $line = (string) ($request['headers']['x-logbook-signature'][0] ?? '');
            self::assertSame(1, preg_match('/t=(\d+)/', $line, $m));
            self::assertSame($times[$i], (int) ($m[1] ?? 0), 'signed when it is sent, not when the run began');
        }
    }

    public function testFailuresRetryOnScheduleThenGiveUp(): void
    {
        $webhook = $this->webhook($this->owner);
        $this->http->statusFor[self::HOOK] = 500;
        $this->logReading('12000');

        $at = new DateTimeImmutable(self::NOW);
        $expected = [60, 300, 1800, 7200, 21600];
        foreach ($expected as $i => $wait) {
            $this->runJob();
            $delivery = $this->queued($webhook)[0];
            self::assertSame($i + 1, $delivery->attempts);
            self::assertEquals($at->modify('+' . $wait . ' seconds'), $delivery->nextAttemptAt);
            // Not due yet: a pass before it sends nothing.
            $this->runJob();
            self::assertSame($i + 1, $this->queued($webhook)[0]->attempts);
            $at = $at->modify('+' . $wait . ' seconds');
            $this->clock->set($at);
        }
        $this->runJob();
        $delivery = $this->queued($webhook)[0];
        self::assertSame(6, $delivery->attempts);
        self::assertNull($delivery->nextAttemptAt, 'given up');
        self::assertCount(6, $this->http->to(self::HOOK));
        $stored = $this->stored($webhook);
        self::assertSame('failed', $stored->lastStatus);
        self::assertSame('HTTP 500', $stored->lastError);
        self::assertSame(6, $stored->failures);

        // Rows go 7 days after they were queued.
        $this->clock->set(new DateTimeImmutable('2026-10-15T09:00:01Z'));
        $this->runJob();
        self::assertSame([], $this->queued($webhook));
    }

    public function testAHostThatStopsAnsweringLeavesTheRestForTheNextPass(): void
    {
        $webhook = $this->webhook($this->owner);
        $this->http->errorFor[self::HOOK] = 'Idle timeout reached for "https://hooks.test/entries?token=abc".';
        foreach (['12000', '12100', '12200', '12300'] as $km) {
            $this->logReading($km);
        }

        $this->runJob();
        $attempted = array_values(array_filter(
            $this->queued($webhook),
            static fn (WebhookDelivery $d): bool => $d->attempts > 0,
        ));
        self::assertCount(count($this->http->to(self::HOOK)), $attempted, 'only what was sent used an attempt');
        self::assertLessThan(4, count($attempted), 'the rest wait for the next pass');
        $stored = $this->stored($webhook);
        self::assertSame('The service did not answer in time.', $stored->lastError, 'never the URL');
    }

    public function testAUserHasAtMostTenWebhooks(): void
    {
        for ($i = 0; $i < WebhookService::MAX_PER_USER; $i++) {
            $this->webhook($this->owner, self::HOOK . '/' . $i);
        }
        $refused = $this->browser->post('/settings/webhooks', [
            'name' => 'One more', 'url' => self::HOOK, 'events' => ['entry.created'],
        ]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('the most one person can have', self::body($refused));
        self::assertCount(10, $this->service($this->app, WebhookRepository::class)->listForUser($this->owner->id));
    }

    public function testSendTestIsLimitedAsChannelTestsAre(): void
    {
        $webhook = $this->webhook($this->owner);
        for ($i = 0; $i < 6; $i++) {
            $this->browser->post('/settings/webhooks/' . $webhook->id . '/test');
        }
        self::assertCount(5, $this->http->to(self::HOOK));
    }

    public function testFiftyFailedAttemptsPauseItAndTheUserIsToldOnceAfterQuietHours(): void
    {
        $webhook = $this->webhook($this->owner);
        $this->giveChannel($this->app, $this->owner, 'ntfy', ['url' => 'https://ntfy.test/garage']);
        $this->connection($this->app)->update('webhooks', ['failures' => 49], ['id' => $webhook->id]);
        // Quiet from 08:00 to 12:00 in the owner's zone (London, BST: 09:00Z is 10:00).
        $this->service($this->app, \Logbook\Repository\SettingRepository::class)->save(
            'notifications',
            ['quiet' => ['start' => '08:00', 'end' => '12:00']],
            \Logbook\Domain\Setting\SettingScope::User,
            $this->owner->id,
        );
        $this->http->statusFor[self::HOOK] = 500;
        $this->logReading('12000');
        $this->logReading('12100');

        $run = $this->runJob();
        self::assertSame(1, $run->counts['paused'] ?? null);
        self::assertCount(1, $this->http->to(self::HOOK), 'its other delivery waits');
        $stored = $this->stored($webhook);
        self::assertSame(WebhookPause::Failures, $stored->pausedReason);
        self::assertTrue($stored->noticePending);
        self::assertSame([], $this->http->to('https://ntfy.test'), 'quiet hours hold the notice');

        $this->clock->set(new DateTimeImmutable('2026-10-08T11:30:00Z'));
        $this->runJob();
        $notices = $this->http->to('https://ntfy.test');
        self::assertCount(1, $notices);
        self::assertStringContainsString('paused', strtolower(implode(' ', array_column($notices, 'body'))));
        self::assertFalse($this->stored($webhook)->noticePending);
        $this->runJob();
        self::assertCount(1, $this->http->to('https://ntfy.test'), 'told once');
        self::assertCount(1, $this->http->to(self::HOOK), 'nothing sent while paused');

        // *Resume*: failures back to 0, the waiting deliveries go.
        unset($this->http->statusFor[self::HOOK]);
        $this->browser->post('/settings/webhooks/' . $webhook->id . '/resume');
        self::assertSame(0, $this->stored($webhook)->failures);
        self::assertNull($this->stored($webhook)->pausedReason);
        $this->clock->set(new DateTimeImmutable('2026-10-08T12:00:00Z'));
        $this->runJob();
        self::assertCount(3, $this->http->to(self::HOOK));
    }

    public function testAMemberMaySendOnlyWhereTheirChannelsMay(): void
    {
        $member = $this->member('driver', ShareLevel::Log);
        $service = $this->service($this->app, WebhookService::class);
        foreach (['http://127.0.0.1:8080/hook', 'http://169.254.169.254/latest'] as $url) {
            $refused = $service->parse($member, ['name' => 'x', 'url' => $url, 'events' => ['entry.created']]);
            self::assertInstanceOf(\Logbook\Support\Validation\ValidationErrors::class, $refused, $url);
            self::assertTrue($refused->has('url'));
        }
        self::assertIsArray(
            $service->parse($this->owner, ['name' => 'x', 'url' => 'http://127.0.0.1:8080/hook', 'events' => ['entry.created']]),
            'an admin\'s own are not restricted',
        );

        // A destination refused at send time (the name moved) is refused, never counted.
        $webhook = $this->webhook($member, 'https://moving.test/hook');
        $this->dns->hosts['moving.test'] = ['127.0.0.1'];
        $this->logReading('12000');
        $this->runJob();
        self::assertSame([], $this->http->to('https://moving.test'));
        $stored = $this->stored($webhook);
        self::assertSame(0, $stored->failures);
        self::assertStringContainsString('not an address your administrator allows', (string) $stored->lastError);
    }

    public function testTheSwitchesHoldDeliveries(): void
    {
        $webhook = $this->webhook($this->owner);
        $this->logReading('12000');

        $apiOff = $this->createRecordingApp(['API_ENABLED' => 'false'] + self::NO_CHANNELS);
        $this->pinClock($apiOff, self::NOW);
        $run = $this->service($apiOff, JobRunner::class)->run($this->service($apiOff, WebhooksJob::class), JobTrigger::Manual);
        self::assertSame('Webhooks are off; nothing sent.', $run->summary);
        self::assertSame([], $this->http->requests);
        self::assertCount(1, $this->queued($webhook), 'still waiting');

        $off = $this->createRecordingApp(['WEBHOOKS_ENABLED' => 'false'] + self::NO_CHANNELS);
        $this->pinClock($off, self::NOW);
        $this->service($off, JobRunner::class)->run($this->service($off, WebhooksJob::class), JobTrigger::Manual);
        self::assertSame([], $this->http->requests);
        $vehicle = $this->ownedVehicles($off, $this->owner->id)[0];
        $this->service($off, \Logbook\Service\Odometer\OdometerService::class)->create(
            $vehicle,
            new \Logbook\Domain\Odometer\OdometerReadingData('13000', new DateTimeImmutable(self::NOW)),
        );
        self::assertCount(1, $this->queued($webhook), 'nothing queued while off');
        self::assertStringContainsString(
            'Webhooks are switched off on this server',
            self::body($this->signedInAs($off)->get('/settings/webhooks')),
        );
    }

    public function testTheSettingsPageWorksWithoutJs(): void
    {
        $page = $this->browser->get('/settings/webhooks');
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));

        $invalid = $this->browser->post('/settings/webhooks', ['name' => '', 'url' => 'ftp://x', 'events' => []]);
        self::assertSame(422, $invalid->getStatusCode());

        $added = $this->browser->post('/settings/webhooks', [
            'name' => 'Node-RED',
            'url' => self::HOOK,
            'events' => ['entry.created', 'reminder.changed'],
        ]);
        self::assertSame(200, $added->getStatusCode());
        self::assertSame('no-store', $added->getHeaderLine('Cache-Control'));
        $body = self::body($added);
        self::assertSame(1, preg_match('/value="(whsec_[A-Za-z0-9_-]{43})"/', $body, $m));
        $first = $m[1] ?? '';
        $webhook = $this->service($this->app, WebhookRepository::class)->listForUser($this->owner->id)[0];
        self::assertSame([WebhookEvent::EntryCreated, WebhookEvent::ReminderChanged], $webhook->events);
        self::assertStringNotContainsString($first, (string) $webhook->secret, 'stored sealed');
        self::assertStringNotContainsString($first, self::body($this->browser->get('/settings/webhooks')), 'shown once');

        // *Send test*.
        $this->browser->post('/settings/webhooks/' . $webhook->id . '/test');
        $test = $this->http->to(self::HOOK)[0];
        self::assertSame('webhook.test', $test['json']['event'] ?? null);

        // *Pause*, then *New secret* (shown once, and the old one stops working).
        $this->browser->post('/settings/webhooks/' . $webhook->id . '/pause');
        self::assertSame(WebhookPause::User, $this->stored($webhook)->pausedReason);
        $renewed = self::body($this->browser->post('/settings/webhooks/' . $webhook->id . '/secret'));
        self::assertSame(1, preg_match('/value="(whsec_[A-Za-z0-9_-]{43})"/', $renewed, $n));
        self::assertNotSame($first, $n[1] ?? null);

        // Someone else's is a 404.
        $other = $this->member('other', null);
        $theirs = $this->webhook($other, 'https://hooks.test/other');
        self::assertSame(404, $this->browser->post('/settings/webhooks/' . $theirs->id . '/pause')->getStatusCode());
        self::assertSame(404, $this->browser->get('/settings/webhooks/' . $theirs->id . '/delete')->getStatusCode());

        // *Delete*, on its own confirmation page.
        $confirm = self::body($this->browser->get('/settings/webhooks/' . $webhook->id . '/delete'));
        self::assertStringContainsString('Delete “Node-RED”?', $confirm);
        $this->browser->post('/settings/webhooks/' . $webhook->id . '/delete');
        self::assertNull($this->service($this->app, WebhookRepository::class)->find($this->owner->id, $webhook->id));
    }

    public function testABackupCarriesWebhooksWithoutTheirSecretAndARestoredOneNeedsANewOne(): void
    {
        $webhook = $this->webhook($this->owner);
        $this->logReading('12000');
        $backups = $this->service($this->app, BackupService::class);
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-backup-');
        $this->tempFiles[] = $path;
        $backups->create($path);

        $zip = new \ZipArchive();
        $zip->open($path);
        $table = (string) $zip->getFromName('database/webhooks.json');
        self::assertFalse($zip->getFromName('database/webhook_deliveries.json'), 'deliveries are not backed up');
        $zip->close();
        self::assertStringNotContainsString('secret', $table);
        self::assertStringNotContainsString((string) $webhook->secret, $table);

        $backups->restore($path);
        $restored = $this->service($this->app, WebhookRepository::class)->find($this->owner->id, $webhook->id);
        self::assertNotNull($restored);
        self::assertSame(WebhookPause::Restored, $restored->pausedReason);
        self::assertTrue($restored->needsSecret());
        self::assertSame([], $this->queued($webhook));
        // The restore ended every session: sign in again.
        $this->browser = $this->browserFor($this->app, 'owner');
        $page = self::body($this->browser->get('/settings/webhooks'));
        self::assertStringContainsString('Restored from a backup: needs a new secret', $page);
        $this->browser->post('/settings/webhooks/' . $webhook->id . '/resume');
        self::assertSame(WebhookPause::Restored, $this->stored($webhook)->pausedReason, 'not without a secret');
        $this->browser->post('/settings/webhooks/' . $webhook->id . '/secret');
        $this->browser->post('/settings/webhooks/' . $webhook->id . '/resume');
        self::assertNull($this->stored($webhook)->pausedReason);
    }

    /**
     * @param list<WebhookEvent> $events
     */
    private function webhook(User $user, string $url = self::HOOK, array $events = []): Webhook
    {
        return $this->service($this->app, WebhookService::class)
            ->create($user, 'Hook ' . $user->id, $url, $events === [] ? WebhookEvent::cases() : $events)['webhook'];
    }

    private function stored(Webhook $webhook): Webhook
    {
        $stored = $this->service($this->app, WebhookRepository::class)->find($webhook->userId, $webhook->id);
        self::assertNotNull($stored);

        return $stored;
    }

    /**
     * @return list<WebhookDelivery> oldest first
     */
    private function queued(Webhook $webhook): array
    {
        return array_reverse($this->service($this->app, WebhookDeliveryRepository::class)->listForWebhook($webhook->id));
    }

    private function member(string $username, ?ShareLevel $level, bool $costs = true): User
    {
        $user = $this->createMember($this->app, $username);
        if ($level !== null) {
            $this->service($this->app, VehicleShareRepository::class)
                ->insert($this->golf->id, $user->id, $level, $costs, false, new DateTimeImmutable(self::NOW));
        }

        return $user;
    }

    private function logReading(string $km): void
    {
        $this->service($this->app, \Logbook\Service\Odometer\OdometerService::class)->create(
            $this->golf,
            new \Logbook\Domain\Odometer\OdometerReadingData($km, $this->clock->now()),
        );
    }

    private function runJob(): JobRun
    {
        return $this->service($this->app, JobRunner::class)
            ->run($this->service($this->app, WebhooksJob::class), JobTrigger::Manual);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function as(User $user, callable $work): mixed
    {
        $context = $this->service($this->app, AccessContext::class);
        $context->apply($user);
        try {
            return $work();
        } finally {
            $context->apply(null);
        }
    }

    /**
     * The payload as docs/api/openapi.json describes it (components.schemas.WebhookPayload).
     *
     * @param array<string, mixed> $payload
     */
    private static function assertMatchesPayloadSchema(array $payload): void
    {
        $document = Reader::readFromJsonFile(Kernel::rootDir() . OpenApiDocument::PATH);
        $schema = $document->components?->schemas['WebhookPayload'] ?? null;
        self::assertInstanceOf(Schema::class, $schema);
        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
        (new SchemaValidator(SchemaValidator::VALIDATE_AS_REQUEST))->validate($decoded, $schema);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function csv(string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-import-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'data.csv', 'text/csv', strlen($contents), UPLOAD_ERR_OK);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function signedInAs(App $app): TestBrowser
    {
        return $this->browserFor($app, 'owner');
    }
}
