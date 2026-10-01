<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Mcp;

use Logbook\Domain\Api\ApiScope;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Support\Api\FailedKeyThrottle;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\McpClient;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The MCP endpoint's door and configuration (spec.md §7.28 *Endpoint*,
 * *Auth*, *Logging*): only a live key opens it, guessing is throttled with
 * the API's counter, a foreign `Origin` is refused, it works behind a
 * subpath, and either switch off makes it a 404.
 */
final class McpAccessTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string ORIGIN = 'https://assistant.example';

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testAMissingMalformedOrRevokedKeyIsUnauthorized(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $keys = $this->service($app, ApiKeyService::class);
        $revoked = $keys->create($owner, 'Old', ApiScope::Read);
        $keys->revoke($owner, $revoked->key->id);

        foreach (['missing' => null, 'malformed' => 'nope', 'revoked' => $revoked->token] as $case => $token) {
            $response = (new McpClient($app, $token))->modern('tools/list');
            self::assertSame(401, $response->getStatusCode(), $case);
            self::assertSame('Bearer realm="Logbook"', $response->getHeaderLine('WWW-Authenticate'), $case);
            self::assertSame(-31401, McpClient::body($response)->get('error', 'code'), $case);
            self::assertArrayNotHasKey('id', McpClient::body($response)->toArray(), 'refused before the message is read');
        }
    }

    public function testFailedKeysAreThrottledWithTheApisCounter(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $bad = new McpClient($app, 'lbk_' . str_repeat('A', 43));
        for ($i = 0; $i < FailedKeyThrottle::MAX_FAILURES; $i++) {
            self::assertSame(401, $bad->modern('tools/list')->getStatusCode());
        }

        $good = new McpClient($app, $this->apiKey($app, $owner), '/mcp', $bad->address);
        $throttled = $good->modern('tools/list');
        self::assertSame(429, $throttled->getStatusCode());
        self::assertSame('600', $throttled->getHeaderLine('Retry-After'));
        self::assertSame(-31429, McpClient::body($throttled)->get('error', 'code'));
        $api = new \Logbook\Tests\Support\ApiClient($app, $this->apiKey($app, $owner), '/api/v1', $bad->address);
        self::assertSame(429, $api->get('/me')->getStatusCode(), 'one counter for the API and MCP');
    }

    public function testAForeignOriginIsRefusedAndAListedOneGetsCors(): void
    {
        $app = $this->createApp(['API_CORS_ORIGINS' => self::ORIGIN]);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $mcp = new McpClient($app, $this->apiKey($app, $owner));

        $foreign = $mcp->modern('tools/list', [], ['Origin' => 'https://evil.example']);
        self::assertSame(403, $foreign->getStatusCode());
        self::assertSame(-31403, McpClient::body($foreign)->get('error', 'code'));
        self::assertSame('', $foreign->getHeaderLine('Access-Control-Allow-Origin'));

        $listed = $mcp->modern('tools/list', [], ['Origin' => self::ORIGIN]);
        self::assertSame(200, $listed->getStatusCode());
        self::assertSame(self::ORIGIN, $listed->getHeaderLine('Access-Control-Allow-Origin'));

        $preflight = $app->handle((new ServerRequestFactory())->createServerRequest('OPTIONS', '/mcp')
            ->withHeader('Origin', self::ORIGIN)
            ->withHeader('Access-Control-Request-Method', 'POST'));
        self::assertSame(204, $preflight->getStatusCode());
        self::assertStringContainsString('Mcp-Method', $preflight->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertStringContainsString('MCP-Protocol-Version', $preflight->getHeaderLine('Access-Control-Allow-Headers'));

        $noCors = $this->createApp();
        $refused = (new McpClient($noCors, $this->apiKey($noCors, $owner)))->modern('tools/list', [], ['Origin' => self::ORIGIN]);
        self::assertSame(403, $refused->getStatusCode(), 'with API_CORS_ORIGINS empty every Origin is refused');
    }

    public function testItWorksUnderTheBasePathWithOrWithoutTheProxyStrippingIt(): void
    {
        $app = $this->createApp(['APP_BASE_PATH' => '/logbook', 'APP_URL' => 'https://cars.example/logbook']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $token = $this->apiKey($app, $owner);

        foreach (['/logbook/mcp', '/mcp'] as $path) {
            $mcp = new McpClient($app, $token, $path);
            $summary = McpClient::structured($mcp->modern('tools/call', [
                'name' => 'vehicle_summary',
                'arguments' => ['vehicle' => $golf->id],
            ]));
            self::assertStringStartsWith('https://cars.example/logbook/', $summary->string('link'), $path);
        }

        $draft = McpClient::structured((new McpClient($app, $token, '/logbook/mcp'))->modern('tools/call', [
            'name' => 'draft_expense',
            'arguments' => ['vehicle' => $golf->id, 'category' => 'parking', 'amount' => '3.20'],
        ]));
        self::assertSame('https://cars.example/logbook/#draft-' . $draft->int('draft_id'), $draft->get('link'));
        $browser = $this->browserFor($app, 'owner');
        $added = $browser->post('/logbook/drafts/' . $draft->int('draft_id') . '/add', ['back' => 'home']);
        self::assertSame('/logbook/#drafts-to-review', $added->getHeaderLine('Location'));
    }

    public function testEitherSwitchOffMakesItANotFound(): void
    {
        foreach (['MCP_ENABLED', 'API_ENABLED'] as $switch) {
            $app = $this->createApp([$switch => 'false']);
            $this->resetDatabase($app);
            $owner = $this->createOwner($app);
            $mcp = new McpClient($app, $this->apiKey($app, $owner));

            self::assertSame(404, $mcp->raw('POST', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->getStatusCode(), $switch);
            self::assertSame(404, $mcp->raw('GET', null)->getStatusCode(), $switch);
        }

        $app = $this->createApp(['MCP_ENABLED' => 'false']);
        $owner = $this->owner($app);
        self::assertSame(200, $this->api($app, $this->apiKey($app, $owner))->get('/me')->getStatusCode(), 'the API stays');
    }

    public function testCallsAreLoggedWithTheKeysNameAndNoContent(): void
    {
        $app = $this->createApp(['AI_LOG_CONTENT' => 'true']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $golf = $this->vehicle($app);
        $mcp = new McpClient($app, $this->apiKey($app, $owner, ApiScope::ReadWrite, 'Claude Desktop'));

        $mcp->modern('tools/list');
        $mcp->modern('resources/list');
        $mcp->modern('tools/call', ['name' => 'vehicle_summary', 'arguments' => ['vehicle' => $golf->id]]);
        $mcp->modern('tools/call', ['name' => 'vehicle_summary', 'arguments' => ['vehicle' => 999999]]);
        $mcp->legacy('resources/read', ['uri' => 'logbook://me']);
        $mcp->modern('prompts/get', ['name' => 'before_service', 'arguments' => ['vehicle' => (string) $golf->id]]);
        $mcp->modern('tools/call', ['name' => 'no_such_tool', 'arguments' => []]);

        $rows = $this->connection($app)->fetchAllAssociative(
            'SELECT task, model, outcome, error_code, content, connection_id FROM ai_requests ORDER BY id',
        );
        self::assertSame([
            ['mcp', 'Claude Desktop', 'ok', null],
            ['mcp', 'Claude Desktop', 'error', 'tool_error'],
            ['mcp', 'Claude Desktop', 'ok', null],
            ['mcp', 'Claude Desktop', 'ok', null],
            ['mcp', 'Claude Desktop', 'refused', 'rpc_32602'],
        ], array_map(static fn (array $row): array => [$row['task'], $row['model'], $row['outcome'], $row['error_code']], $rows));
        foreach ($rows as $row) {
            self::assertNull($row['content'], 'never any content, whatever AI_LOG_CONTENT says');
            self::assertNull($row['connection_id']);
        }
    }

    public function testTheKeysPageShowsTheMcpAddress(): void
    {
        $app = $this->createApp(['APP_URL' => 'https://cars.example']);
        $this->resetDatabase($app);
        $this->createOwner($app);

        $page = (string) $this->browserFor($app, 'owner')->get('/settings/api-keys')->getBody();

        self::assertStringContainsString('https://cars.example/mcp', $page);
    }
}
