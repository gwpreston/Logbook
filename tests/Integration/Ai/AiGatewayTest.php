<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai;

use DateTimeImmutable;
use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ConnectionSettings;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Ai\Outcome;
use Logbook\Domain\Ai\RequestRecord;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Kernel;
use Logbook\Repository\AiBusyRepository;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiRequestRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\AiPreferences;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ImageInput;
use Logbook\Service\Ai\Provider\ResponseFormat;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Tests\Support\AiTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * AiGateway (spec.md §5 *AI adapters*, §7.25): routing, the
 * acknowledgement, secrets, limits, the one-at-a-time lock and the usage
 * log, against a recorded provider. Nothing leaves the test.
 */
final class AiGatewayTest extends AiTestCase
{
    private const string NOW = '2026-10-15T12:00:00Z';

    public function testTextAndReceiptsCanUseDifferentConnections(): void
    {
        [$app, $owner] = $this->setUpApp();
        $local = $this->addConnection($app, 'Desktop', AdapterType::Ollama, 'http://192.168.1.20:11434', Location::Network);
        $cloud = $this->cloud($app);
        $this->acknowledge($app, $cloud, $owner);
        $this->assign($app, $local, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $this->assign($app, $cloud, 'gpt-5', AiTaskName::ReadDocument, [Capability::Images, Capability::Json]);
        $this->provider->queue('ollama/chat', 'openai/json_schema');

        $gateway = $this->service($app, AiGateway::class);
        $text = $gateway->run($owner, AiTaskName::Ask, new ChatRequest([ChatMessage::user('Reply with the single word OK.')]));
        self::assertSame('OK', $text->text);
        self::assertSame('http://192.168.1.20:11434/v1/chat/completions', $this->provider->requests[0]['url']);
        self::assertSame('llama3.2:3b', $this->provider->requests[0]['json']['model']);
        self::assertArrayNotHasKey('authorization', $this->provider->requests[0]['headers']);

        $receipt = $gateway->run($owner, AiTaskName::ReadDocument, new ChatRequest(
            [ChatMessage::user('Give the colour "red" and the count 3.')],
            response: new ResponseFormat('test_object', self::SCHEMA),
        ));
        self::assertSame(['colour' => 'red', 'count' => 3], $receipt->object);
        self::assertSame('https://api.openai.com/v1/chat/completions', $this->provider->requests[1]['url']);
        self::assertSame('Bearer sk-test', $this->provider->requests[1]['headers']['authorization']);
        self::assertSame(
            ['type' => 'json_schema', 'json_schema' => ['name' => 'test_object', 'schema' => self::SCHEMA]],
            $this->provider->requests[1]['json']['response_format'],
        );
    }

    public function testAnInternetConnectionSendsNothingUntilAcknowledgedAndAUrlChangeAsksAgain(): void
    {
        [$app, $owner] = $this->setUpApp();
        $cloud = $this->cloud($app);
        $this->assign($app, $cloud, 'gpt-5', AiTaskName::Ask, [Capability::Tools]);

        self::assertSame(ErrorCode::NotAcknowledged, $this->failure($app, $owner)->error);
        self::assertSame([], $this->provider->requests, 'nothing is sent before the acknowledgement');

        $connections = $this->service($app, AiConnectionRepository::class);
        $this->acknowledge($app, $cloud, $owner);
        $this->provider->queue('openai/text');
        $this->service($app, AiGateway::class)->run($owner, AiTaskName::Ask, self::hello());
        self::assertCount(1, $this->provider->requests);

        $connection = $connections->find($cloud);
        self::assertNotNull($connection);
        $connections->update($cloud, new ConnectionSettings(
            $connection->name,
            $connection->adapter,
            'https://openrouter.ai/api/v1',
            Location::Internet,
            [],
            60,
            true,
            null,
            8,
            null,
            true,
        ), new DateTimeImmutable(self::NOW));
        self::assertFalse($connections->find($cloud)?->isAcknowledged() ?? true, 'a URL change clears it');
        self::assertSame(ErrorCode::NotAcknowledged, $this->failure($app, $owner)->error);
        self::assertCount(1, $this->provider->requests);
    }

    public function testALanNameThatNowResolvesPubliclyNeedsTheAcknowledgement(): void
    {
        [$app, $owner] = $this->setUpApp();
        $this->dns->hosts['gpu.example.com'] = ['192.168.1.20'];
        $id = $this->network($app, 'GPU box', 'http://gpu.example.com:8080/v1');
        $this->assign($app, $id, 'qwen', AiTaskName::Ask, [Capability::Tools]);
        $this->dns->hosts['gpu.example.com'] = ['203.0.113.9'];

        self::assertSame(ErrorCode::NotAcknowledged, $this->failure($app, $owner)->error);
        self::assertSame([], $this->provider->requests);
        $connection = $this->service($app, AiConnectionRepository::class)->find($id);
        self::assertSame(Location::Internet, $connection?->location, 'the new class is recorded');
    }

    public function testAKeyIsReadFromTheEnvironmentAtCallTime(): void
    {
        [$app, $owner] = $this->setUpApp(['OPENAI_KEY' => 'sk-from-env']);
        $id = $this->network($app, 'Proxy', 'http://10.0.0.5/v1', 'env:OPENAI_KEY');
        $this->assign($app, $id, 'gpt-5', AiTaskName::Ask, [Capability::Tools]);
        $this->provider->queue('openai/text');

        $this->service($app, AiGateway::class)->run($owner, AiTaskName::Ask, self::hello());

        self::assertSame('Bearer sk-from-env', $this->provider->last()['headers']['authorization']);
    }

    public function testAnUnsetVariableOrAnotherSessionSecretSendsNothingAndSaysWhy(): void
    {
        [$app, $owner] = $this->setUpApp();
        $id = $this->network($app, 'Proxy', 'http://10.0.0.5/v1', 'env:MISSING_KEY');
        $this->assign($app, $id, 'gpt-5', AiTaskName::Ask, [Capability::Tools]);

        $unset = $this->failure($app, $owner);
        self::assertSame(ErrorCode::SecretUnreadable, $unset->error);
        self::assertSame('MISSING_KEY', $unset->variable);
        self::assertSame('ai.error.secret_unset', $unset->messageKey());

        $sealed = $this->network($app, 'Sealed', 'http://10.0.0.6/v1', 'sk-sealed');
        $this->assign($app, $sealed, 'gpt-5', AiTaskName::Ask, [Capability::Tools]);
        $rotated = $this->aiApp(['SESSION_SECRET' => 'another-secret-another-secret-xx']);
        $this->pinClock($rotated, self::NOW);
        $rotatedOwner = $this->service($rotated, UserRepository::class)->find($owner->id);
        self::assertNotNull($rotatedOwner);

        $unreadable = $this->failure($rotated, $rotatedOwner);
        self::assertSame(ErrorCode::SecretUnreadable, $unreadable->error);
        self::assertSame('ai.error.secret_unreadable', $unreadable->messageKey());
        self::assertSame([], $this->provider->requests);
    }

    public function testATooLargeRequestIsRefusedBeforeSending(): void
    {
        [$app, $owner] = $this->setUpApp();
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server, maxMb: 1);
        $this->assign($app, $id, 'gemma3:12b', AiTaskName::Ask, [Capability::Tools, Capability::Images]);

        $error = $this->failure($app, $owner, new ChatRequest([
            ChatMessage::user('Read this', [new ImageInput(str_repeat('x', 1024 * 1024), 'image/jpeg')]),
        ]));

        self::assertSame(ErrorCode::TooLarge, $error->error);
        self::assertSame([], $this->provider->requests);
        $this->assertLogged($app, Outcome::Refused, 'too_large');
    }

    public function testTheMonthlyCapCountsTheCalendarMonthInTheAppTimeZone(): void
    {
        // 23:30 UTC on 31 October is already 1 November in Berlin.
        [$app, $owner] = $this->setUpApp(['APP_TIMEZONE' => 'Europe/Berlin'], '2026-10-31T23:30:00Z');
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server, cap: 500);
        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $requests = $this->service($app, AiRequestRepository::class);
        // 23:00 on 31 October in Berlin: last month there.
        $requests->log(self::usage($owner, $id, 600, new DateTimeImmutable('2026-10-31T22:00:00Z')));
        $this->provider->queue('ollama/chat');

        $this->service($app, AiGateway::class)->run($owner, AiTaskName::Ask, self::hello());
        self::assertCount(1, $this->provider->requests, 'a new month in Berlin: not capped');

        [$app, $owner] = $this->setUpApp(['APP_TIMEZONE' => 'Europe/Berlin'], '2026-10-31T22:30:00Z');
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server, cap: 500);
        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $last = new DateTimeImmutable('2026-10-31T22:00:00Z');
        $this->service($app, AiRequestRepository::class)->log(self::usage($owner, $id, 600, $last));

        self::assertSame(ErrorCode::CapReached, $this->failure($app, $owner)->error);
        self::assertSame([], $this->provider->requests);
    }

    public function testOneRequestAtATimePerUserAndADeadLockIsTakenOver(): void
    {
        [$app, $owner] = $this->setUpApp();
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server);
        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $busy = $this->service($app, AiBusyRepository::class);
        $now = new DateTimeImmutable(self::NOW);
        self::assertTrue($busy->acquire($owner->id, $now, $now->modify('+150 seconds')));

        $error = $this->failure($app, $owner);
        self::assertSame(ErrorCode::Busy, $error->error);
        self::assertSame('ai.error.busy', $error->messageKey());
        self::assertSame([], $this->provider->requests);
        $this->assertLogged($app, Outcome::Refused, 'busy');

        // Another user is not held up.
        $member = $this->createMember($app);
        $this->provider->queue('ollama/chat');
        $this->service($app, AiGateway::class)->run($member, AiTaskName::Ask, self::hello());
        self::assertCount(1, $this->provider->requests);

        // A lock left by a request that died expires.
        $busy->release($owner->id);
        self::assertTrue($busy->acquire($owner->id, $now->modify('-10 minutes'), $now->modify('-5 minutes')));
        $this->provider->queue('ollama/chat');
        $this->service($app, AiGateway::class)->run($owner, AiTaskName::Ask, self::hello());
        self::assertCount(2, $this->provider->requests);
        self::assertTrue($busy->acquire($owner->id, $now, $now->modify('+1 minute')), 'released after the call');
    }

    public function testTheUsageLogKeepsNoContentUnlessAsked(): void
    {
        [$app, $owner] = $this->setUpApp();
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server);
        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $this->provider->queue('ollama/chat');

        $this->service($app, AiGateway::class)->run($owner, AiTaskName::Ask, self::hello());

        $row = $this->connection($app)->fetchAssociative('SELECT * FROM ai_requests');
        self::assertIsArray($row);
        self::assertSame('ask', $row['task']);
        self::assertSame('ok', $row['outcome']);
        self::assertSame('llama3.2:3b', $row['model']);
        self::assertEquals(31, $row['tokens_in']);
        self::assertEquals(2, $row['tokens_out']);
        self::assertNull($row['content']);

        [$app, $owner] = $this->setUpApp(['AI_LOG_CONTENT' => 'true']);
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server);
        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $this->provider->queue('ollama/chat');
        $this->service($app, AiGateway::class)->run($owner, AiTaskName::Ask, self::hello());

        $content = $this->connection($app)->fetchOne('SELECT content FROM ai_requests');
        self::assertIsString($content);
        self::assertStringContainsString('Reply with the single word OK.', $content);
        self::assertStringContainsString('"response":"OK"', $content);
    }

    public function testAFailureIsLoggedAndRedactedAndNeverFallsBack(): void
    {
        [$app, $owner] = $this->setUpApp();
        $id = $this->network($app, 'Proxy', 'http://10.0.0.5/v1', 'my-secret-proxy-key');
        $this->assign($app, $id, 'gpt-5', AiTaskName::Ask, [Capability::Tools]);
        $other = $this->addConnection($app, 'Other', AdapterType::Ollama, 'http://localhost:11434', Location::Server);
        $this->assign($app, $other, 'llama3.2:3b', AiTaskName::ReadText, [Capability::Json]);
        $this->provider->queue(new MockResponse(
            '{"error":{"message":"Key my-secret-proxy-key is not valid"}}',
            ['http_code' => 401],
        ));

        $error = $this->failure($app, $owner);

        self::assertSame(ErrorCode::Auth, $error->error);
        self::assertStringNotContainsString('my-secret-proxy-key', $error->detail);
        self::assertStringContainsString('[redacted]', $error->detail);
        self::assertSame('Proxy', $error->connectionName);
        self::assertCount(1, $this->provider->requests, 'no other connection is tried');
        $this->assertLogged($app, Outcome::Error, 'auth');
        $log = (string) file_get_contents(Kernel::rootDir() . '/var/log/testing.log');
        self::assertStringNotContainsString('my-secret-proxy-key', $log);
    }

    public function testReadTextUsesAsksModelWhenItHasJsonOutput(): void
    {
        [$app, $owner] = $this->setUpApp();
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server);
        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $status = $this->service($app, AiStatus::class);
        self::assertNull($status->model(AiTaskName::ReadText), 'ask\'s model has no JSON output');

        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools, Capability::Json]);
        $model = $status->model(AiTaskName::ReadText);
        self::assertNotNull($model);
        self::assertSame('llama3.2:3b', $model->name);
    }

    public function testAiOffForTheInstallOrTheUserSendsNothing(): void
    {
        [$app, $owner] = $this->setUpApp();
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server);
        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $this->service($app, AiPreferences::class)->set($owner->id, false);

        self::assertSame(ErrorCode::Disabled, $this->failure($app, $owner)->error);
        self::assertFalse($this->service($app, AiStatus::class)->isOnFor($owner));

        $off = $this->aiApp(['AI_ENABLED' => 'false']);
        $this->service($off, AiPreferences::class)->set($owner->id, true);
        self::assertFalse($this->service($off, AiStatus::class)->isSetUp());
        self::assertSame(ErrorCode::Disabled, $this->failure($off, $owner)->error);
        self::assertSame([], $this->provider->requests);
    }

    public function testRetentionDeletesUsageOlderThanNinetyDaysEvenWithRemindersOff(): void
    {
        [$app, $owner] = $this->setUpApp();
        $this->service($app, FeatureToggles::class)->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => $f !== Feature::Reminders,
        )));
        $requests = $this->service($app, AiRequestRepository::class);
        $requests->log(self::usage($owner, null, 10, new DateTimeImmutable('2026-07-16T11:00:00Z')));
        $requests->log(self::usage($owner, null, 10, new DateTimeImmutable('2026-07-18T11:00:00Z')));

        $summary = $this->service($app, ScheduledTasks::class)->run();

        self::assertSame(1, $summary->aiRowsDeleted);
        self::assertEquals(1, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM ai_requests'));
    }

    private const array SCHEMA = [
        'type' => 'object',
        'properties' => ['colour' => ['type' => 'string'], 'count' => ['type' => 'integer']],
        'required' => ['colour', 'count'],
        'additionalProperties' => false,
    ];

    /**
     * @param array<string, string> $env
     * @return array{App<ContainerInterface>, User}
     */
    private function setUpApp(array $env = [], string $now = self::NOW): array
    {
        $app = $this->aiApp($env);
        $this->pinClock($app, $now);
        $this->resetDatabase($app);

        return [$app, $this->createOwner($app)];
    }

    private static function hello(): ChatRequest
    {
        return new ChatRequest([ChatMessage::user('Reply with the single word OK.')]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function acknowledge(App $app, int $connection, User $admin): void
    {
        $this->service($app, AiConnectionRepository::class)
            ->acknowledge($connection, $admin->id, 'https://api.openai.com/v1', new DateTimeImmutable(self::NOW));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function failure(App $app, User $user, ?ChatRequest $request = null): AiFailure
    {
        try {
            $this->service($app, AiGateway::class)->run($user, AiTaskName::Ask, $request ?? self::hello());
        } catch (AiFailure $e) {
            return $e;
        }
        self::fail('Expected an AiFailure.');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function assertLogged(App $app, Outcome $outcome, string $code): void
    {
        $row = $this->connection($app)->fetchAssociative('SELECT outcome, error_code, content FROM ai_requests ORDER BY id DESC');
        self::assertIsArray($row);
        self::assertSame($outcome->value, $row['outcome']);
        self::assertSame($code, $row['error_code']);
        self::assertNull($row['content']);
    }

    private static function usage(User $user, ?int $connection, int $tokens, DateTimeImmutable $at): RequestRecord
    {
        return new RequestRecord($user->id, 'ask', $connection, 'llama3.2:3b', $tokens, 0, 100, Outcome::Ok, null, null, $at);
    }
}
