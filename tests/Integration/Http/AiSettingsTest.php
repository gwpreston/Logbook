<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\Location;
use Logbook\Repository\AiModelRepository;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Backup\BackupService;
use Logbook\Tests\Support\AiTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use ZipArchive;

/**
 * Settings → AI (spec.md §7.25) through the pages, against a recorded
 * provider: the phase's acceptance criteria, secrets never rendered, and
 * Logbook unchanged while AI is not set up.
 */
final class AiSettingsTest extends AiTestCase
{
    private const string NOW = '2026-10-15T12:00:00Z';
    private const string KEY = 'sk-live-very-secret-key-123456';

    public function testOllamaOnAnotherComputerIsYourNetworkPassesTestAndCanBeAssigned(): void
    {
        [$app, $browser] = $this->admin();

        $saved = $browser->post('/settings/ai/connections/new', [
            'preset' => 'ollama',
            'name' => 'Ollama on the desktop',
            'adapter' => 'ollama',
            'base_url' => 'http://192.168.1.20:11434/',
            'verify_tls' => '1',
            'max_request_mb' => '8',
            'enabled' => '1',
        ]);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        $page = $browser->follow($saved);
        $html = self::body($page);
        self::assertStringContainsString('data-ai-location="network"', $html);
        self::assertStringContainsString('Your network', $html);
        self::assertStringNotContainsString('data-ai-acknowledge', $html);
        $show = $saved->getHeaderLine('Location');

        $this->provider->queue('ollama/tags', 'ollama/show_llama', 'ollama/show_gemma');
        $html = self::body($browser->follow($browser->post($show . '/models', ['do' => 'refresh'])));
        self::assertStringContainsString('The server listed 2 models.', $html);
        self::assertStringContainsString('data-ai-model="llama3.2:3b"', $html);
        $browser->post($show . '/models', ['do' => 'add', 'name' => 'llama3.2:3b']);
        $model = $this->service($app, AiModelRepository::class)->listAdded()[0];
        self::assertTrue($model->tools, 'Ollama reported tools');

        $this->provider->queue('ollama/tags', 'ollama/show_llama', 'ollama/show_gemma', 'ollama/chat', 'openai/tool_call');
        $html = self::body($browser->follow($browser->post($show . '/test', ['model' => (string) $model->id])));
        self::assertStringContainsString('Test passed.', $html);
        self::assertStringContainsString('Tool call: ok', $html);
        self::assertSame('http://192.168.1.20:11434/v1/chat/completions', $this->provider->requests[6]['url']);

        $browser->get('/settings/ai');
        $tasks = $browser->post('/settings/ai/tasks', ['model_ask' => (string) $model->id]);
        self::assertSame(303, $tasks->getStatusCode(), self::body($tasks));
        self::assertSame('llama3.2:3b', $this->service($app, AiStatus::class)->model(AiTaskName::Ask)?->name);
        $html = self::body($browser->get('/settings/ai'));
        self::assertStringContainsString('Runs on Ollama on the desktop.', $html);
    }

    public function testACloudProviderIsInternetAndSendsNothingUntilAcknowledged(): void
    {
        [$app, $browser] = $this->admin();

        $saved = $browser->post('/settings/ai/connections/new', [
            'name' => 'OpenAI',
            'adapter' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => self::KEY,
            'verify_tls' => '1',
            'max_request_mb' => '8',
            'enabled' => '1',
        ]);
        $show = $saved->getHeaderLine('Location');
        $html = self::body($browser->follow($saved));
        self::assertStringContainsString('data-ai-location="internet"', $html);
        self::assertStringContainsString('data-ai-acknowledge', $html);
        self::assertStringContainsString('will be sent to api.openai.com.', $html);
        self::assertStringContainsString('60 seconds', $html, 'the internet default timeout');

        $html = self::body($browser->follow($browser->post($show . '/models', ['do' => 'refresh'])));
        self::assertStringContainsString('could not be read', $html);
        self::assertSame([], $this->provider->requests, 'nothing is sent before the acknowledgement');

        $browser->post($show . '/acknowledge', ['acknowledge' => '1']);
        $this->provider->queue('openai/models');
        $html = self::body($browser->follow($browser->post($show . '/models', ['do' => 'refresh'])));
        self::assertStringContainsString('The server listed 2 models.', $html);
        self::assertSame('Bearer ' . self::KEY, $this->provider->last()['headers']['authorization']);
        self::assertStringContainsString('You agreed to send data to api.openai.com', $html);
    }

    public function testAGatewaysAcknowledgementNamesTheProvidersItRoutesTo(): void
    {
        [, $browser] = $this->admin();
        $saved = $browser->post('/settings/ai/connections/new', [
            'name' => 'OpenRouter',
            'adapter' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'max_request_mb' => '8',
            'enabled' => '1',
        ]);

        self::assertStringContainsString(
            'sent to openrouter.ai and the provider it routes each model to.',
            self::body($browser->follow($saved)),
        );
    }

    public function testSecretsAreNeverRenderedAndAreEncryptedAtRest(): void
    {
        [$app, $browser] = $this->admin();
        $saved = $browser->post('/settings/ai/connections/new', [
            'name' => 'Proxy',
            'adapter' => 'openai_compatible',
            'base_url' => 'http://10.0.0.5/v1',
            'api_key' => self::KEY,
            'headers' => "X-Proxy-Token: header-secret-value-99\nHTTP-Referer: env:LOGBOOK_REFERER",
            'max_request_mb' => '8',
            'enabled' => '1',
        ]);
        $show = $saved->getHeaderLine('Location');

        foreach ([$show, $show . '/edit', '/settings/ai'] as $path) {
            $html = self::body($browser->get($path));
            self::assertStringNotContainsString(self::KEY, $html, $path);
            self::assertStringNotContainsString('header-secret-value-99', $html, $path);
        }
        $edit = self::body($browser->get($show . '/edit'));
        self::assertStringContainsString('Saved. It is never shown again.', $edit);
        self::assertStringContainsString('Set LOGBOOK_REFERER', $edit);

        $stored = $this->connection($app)->fetchFirstColumn('SELECT value FROM ai_secrets ORDER BY slot');
        self::assertCount(3, $stored);
        self::assertIsString($stored[0]);
        self::assertStringStartsWith('v1:', $stored[0]);
        self::assertSame('env:LOGBOOK_REFERER', $stored[1]);
        foreach ($stored as $value) {
            self::assertIsString($value);
            self::assertStringNotContainsString(self::KEY, $value);
            self::assertStringNotContainsString('header-secret-value-99', $value);
        }

        // A form shown again after an error never puts a typed key back.
        $error = $browser->post($show . '/edit', [
            'name' => 'Proxy',
            'adapter' => 'openai_compatible',
            'base_url' => 'ftp://10.0.0.5',
            'api_key' => 'sk-typed-but-refused-555555',
            'headers' => 'X-Other: typed-header-value-777',
            'max_request_mb' => '8',
        ]);
        self::assertSame(422, $error->getStatusCode());
        $html = self::body($error);
        self::assertStringNotContainsString('sk-typed-but-refused-555555', $html);
        self::assertStringNotContainsString('typed-header-value-777', $html);

        // An empty key field keeps the saved one.
        $browser->post($show . '/edit', [
            'name' => 'Proxy renamed',
            'adapter' => 'openai_compatible',
            'base_url' => 'http://10.0.0.5/v1',
            'api_key' => '',
            'max_request_mb' => '8',
            'enabled' => '1',
        ]);
        self::assertCount(3, $this->connection($app)->fetchFirstColumn('SELECT value FROM ai_secrets'));
    }

    public function testAnotherSessionSecretAsksForTheKeyAgain(): void
    {
        [$app, $browser] = $this->admin();
        $show = $browser->post('/settings/ai/connections/new', [
            'name' => 'Proxy',
            'adapter' => 'openai_compatible',
            'base_url' => 'http://10.0.0.5/v1',
            'api_key' => self::KEY,
            'max_request_mb' => '8',
            'enabled' => '1',
        ])->getHeaderLine('Location');

        $rotated = $this->aiApp(['SESSION_SECRET' => 'another-secret-another-secret-xx']);
        $this->pinClock($rotated, self::NOW);
        $browser = $this->browserFor($rotated, 'owner');
        self::assertStringContainsString('Re-enter the key', self::body($browser->get('/settings/ai')));
        self::assertStringContainsString('Re-enter the key', self::body($browser->get($show)));
    }

    public function testBackupsCarryConnectionsButNeverSecretsOrTheUsageLog(): void
    {
        [$app] = $this->admin();
        $id = $this->cloud($app, self::KEY);
        $this->assign($app, $id, 'gpt-5', AiTaskName::Ask, [Capability::Tools]);
        $dir = $this->uploadDir() . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        $path = $dir . '/backup.zip';

        $manifest = $this->service($app, BackupService::class)->create($path);

        self::assertSame(1, $manifest->rows('ai_connections'));
        self::assertSame(1, $manifest->rows('ai_models'));
        self::assertSame(1, $manifest->rows('ai_tasks'));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertFalse($zip->getFromName('database/ai_secrets.json'));
        self::assertFalse($zip->getFromName('database/ai_requests.json'));
        self::assertFalse($zip->getFromName('database/ai_busy.json'));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $contents = (string) $zip->getFromIndex($i);
            self::assertStringNotContainsString(self::KEY, $contents);
        }
        $zip->close();
    }

    public function testMembersGetNotFoundAndNoLink(): void
    {
        [$app, $admin] = $this->admin();
        $this->createMember($app);
        $member = $this->browserFor($app, 'partner');

        foreach (['/settings/ai', '/settings/ai/connections/new'] as $path) {
            self::assertSame(404, $member->get($path)->getStatusCode(), $path);
        }
        self::assertSame(404, $member->post('/settings/ai/tasks', ['model_ask' => '1'])->getStatusCode());
        self::assertStringNotContainsString('/settings/ai', self::body($member->get('/settings')));
        self::assertStringContainsString('href="/settings/ai"', self::body($admin->get('/settings')));
    }

    public function testWithoutAiSetUpLogbookLooksAsBeforeAndSendsNothing(): void
    {
        [$app, $admin] = $this->admin();
        $this->createMember($app);
        $member = $this->browserFor($app, 'partner');

        foreach ([$admin, $member] as $browser) {
            foreach (['/', '/settings', '/settings/modules', '/garage'] as $path) {
                $html = self::body($browser->get($path));
                self::assertStringNotContainsString('data-ai-use', $html, $path);
                self::assertStringNotContainsString('Use AI features', $html, $path);
                self::assertStringNotContainsString('name="ai_ask"', $html, $path);
            }
        }
        self::assertSame(404, $member->post('/settings/ai-use', ['use_ai' => '0'])->getStatusCode());
        self::assertSame([], $this->provider->requests);
    }

    public function testOnceSetUpEveryUserSeesTheirSwitchAndTheModules(): void
    {
        [$app, $admin] = $this->admin();
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server);
        $this->assign($app, $id, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $member = $this->createMember($app);
        $browser = $this->browserFor($app, 'partner');

        $html = self::body($browser->get('/settings'));
        self::assertStringContainsString('data-ai-use', $html);
        self::assertStringContainsString('name="use_ai" value="1" checked', $html, 'on by default (#67)');
        $browser->post('/settings/ai-use', ['use_ai' => '0']);
        self::assertFalse($this->service($app, AiStatus::class)->isOnFor($member));
        self::assertStringNotContainsString('name="use_ai" value="1" checked', self::body($browser->get('/settings')));

        self::assertStringContainsString('name="ai_ask" value="1" checked', self::body($admin->get('/settings/modules')));
    }

    public function testAiEnabledFalseRemovesTheWholeArea(): void
    {
        $app = $this->aiApp(['AI_ENABLED' => 'false']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        self::assertSame(404, $browser->get('/settings/ai')->getStatusCode());
        self::assertStringNotContainsString('/settings/ai', self::body($browser->get('/settings')));
    }

    public function testLoggingContentShowsAWarning(): void
    {
        [, $browser] = $this->admin(['AI_LOG_CONTENT' => 'true']);

        self::assertStringContainsString('data-ai-log-content', self::body($browser->get('/settings/ai')));
    }

    public function testATaskRefusesAModelWithoutWhatItNeeds(): void
    {
        [$app, $browser] = $this->admin();
        $id = $this->addConnection($app, 'Ollama', AdapterType::Ollama, 'http://localhost:11434', Location::Server);
        $model = $this->service($app, AiModelRepository::class)->add($id, 'tinyllama', new DateTimeImmutable(self::NOW));

        $browser->get('/settings/ai');
        $response = $browser->post('/settings/ai/tasks', ['model_ask' => (string) $model]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('This model can&#039;t do what the task needs.', self::body($response));
        self::assertFalse($this->service($app, AiStatus::class)->isSetUp());
    }

    /**
     * @param array<string, string> $env
     * @return array{App<ContainerInterface>, TestBrowser}
     */
    private function admin(array $env = []): array
    {
        $app = $this->aiApp($env);
        $this->pinClock($app, self::NOW);

        return [$app, $this->signedIn($app)];
    }
}
