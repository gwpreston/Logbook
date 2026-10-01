<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Mcp;

use Logbook\Domain\Api\ApiScope;
use Logbook\Kernel;
use Logbook\Service\Mcp\McpVersion;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\McpClient;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The MCP transport and protocol (spec.md §7.28): both eras stateless on
 * one endpoint, the modern revision's headers checked against the body, and
 * every error a JSON-RPC error with the status the transport names. Each
 * answer is checked against the specification's schema by McpClient.
 */
final class McpProtocolTest extends AppTestCase
{
    use ApiFixtures;

    private const string EXAMPLES = '/tests/Fixtures/mcp/2026-07-28/examples/';

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testDiscoverNamesEveryVersionAndTheCapabilities(): void
    {
        $mcp = $this->client();

        $result = McpClient::result($mcp->modern('server/discover'));

        self::assertSame('complete', $result['resultType']);
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18'], $result['supportedVersions']);
        self::assertSame(['tools', 'resources', 'prompts'], array_keys($result['capabilities']));
        self::assertSame(0, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
        self::assertSame(Kernel::version(), $result['_meta']['io.modelcontextprotocol/serverInfo']['version']);
        self::assertNotSame('', $result['instructions']);
    }

    public function testLegacyClientsInitializeAndCarryOnWithoutASession(): void
    {
        $mcp = $this->client();

        foreach (['2025-11-25' => '2025-11-25', '2025-06-18' => '2025-06-18', '2025-03-26' => '2025-11-25'] as $asked => $given) {
            $response = $mcp->legacy('initialize', [
                'protocolVersion' => $asked,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'Old', 'version' => '1'],
            ], null);
            $result = McpClient::result($response);
            self::assertSame($given, $result['protocolVersion'], $asked);
            self::assertSame('logbook', $result['serverInfo']['name']);
            self::assertArrayNotHasKey('resultType', $result, 'legacy results keep their own shape');
            self::assertSame('', $response->getHeaderLine('Mcp-Session-Id'), 'no session is issued');
        }

        $initialized = $mcp->raw('POST', '{"jsonrpc":"2.0","method":"notifications/initialized"}', [
            'MCP-Protocol-Version' => '2025-11-25',
        ]);
        self::assertSame(202, $initialized->getStatusCode());
        self::assertSame('', (string) $initialized->getBody());

        self::assertSame([], McpClient::result($mcp->legacy('ping')));
        self::assertNotEmpty(McpClient::result($mcp->legacy('tools/list'))['tools']);
        self::assertNotEmpty(McpClient::result($mcp->legacy('tools/list', [], '2025-06-18'))['tools']);
        self::assertNotEmpty(
            McpClient::result($mcp->legacy('tools/list', [], null))['tools'],
            'no MCP-Protocol-Version: served as 2025-06-18',
        );
    }

    public function testTheSpecificationsOwnExampleRequestsAreAnswered(): void
    {
        $mcp = $this->client();
        foreach (['DiscoverRequest', 'ListToolsRequest', 'ListResourcesRequest', 'ListResourceTemplatesRequest', 'ListPromptsRequest'] as $type) {
            foreach (glob(Kernel::rootDir() . self::EXAMPLES . $type . '/*.json') ?: [] as $file) {
                $body = (string) file_get_contents($file);
                $message = json_decode($body, true);
                self::assertIsArray($message);
                $response = $mcp->raw('POST', $body, [
                    'MCP-Protocol-Version' => McpVersion::MODERN,
                    'Mcp-Method' => $message['method'],
                ]);
                self::assertSame(200, $response->getStatusCode(), $type . ': ' . $response->getBody());
                $answer = McpClient::body($response);
                self::assertSame($message['id'], $answer['id'], $type);
                self::assertSame('complete', $answer['result']['resultType'], $type);
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, int, int}>
     */
    public static function badHeaders(): iterable
    {
        yield 'no MCP-Protocol-Version' => [['MCP-Protocol-Version' => ''], 400, -32020];
        yield 'MCP-Protocol-Version differs from _meta' => [['MCP-Protocol-Version' => '2099-01-01'], 400, -32020];
        yield 'no Mcp-Method' => [['Mcp-Method' => ''], 400, -32020];
        yield 'Mcp-Method differs' => [['Mcp-Method' => 'tools/list'], 400, -32020];
        yield 'no Mcp-Name' => [['Mcp-Name' => ''], 400, -32020];
        yield 'Mcp-Name differs' => [['Mcp-Name' => 'costs'], 400, -32020];
        yield 'Mcp-Name bad Base64' => [['Mcp-Name' => '=?base64?***?='], 400, -32020];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('badHeaders')]
    public function testModernHeadersMustMatchTheBody(array $headers, int $status, int $code): void
    {
        $mcp = $this->client();

        $response = $mcp->modern('tools/call', ['name' => 'find_vehicles', 'arguments' => ['query' => 'Golf']], $headers);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, McpClient::body($response)['error']['code']);
        self::assertSame(1, McpClient::body($response)['id'], 'the id is kept once it could be read');
    }

    public function testABase64McpNameIsDecodedBeforeItIsCompared(): void
    {
        $mcp = $this->client();

        $response = $mcp->modern('tools/call', ['name' => 'find_vehicles', 'arguments' => ['query' => 'Golf']], [
            'Mcp-Name' => '=?base64?' . base64_encode('find_vehicles') . '?=',
        ]);

        self::assertFalse(McpClient::result($response)['isError']);
    }

    public function testVersionsAndMetadataAreChecked(): void
    {
        $mcp = $this->client();

        $future = $mcp->raw('POST', (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2099-01-01',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ]), ['MCP-Protocol-Version' => '2099-01-01', 'Mcp-Method' => 'tools/list']);
        self::assertSame(400, $future->getStatusCode());
        $error = McpClient::body($future)['error'];
        self::assertSame(-32022, $error['code']);
        self::assertSame(['supported' => McpVersion::supported(), 'requested' => '2099-01-01'], $error['data']);
        McpClient::assertConforms(McpClient::body($future), 'UnsupportedProtocolVersionError');

        $old = $mcp->legacy('tools/list', [], '2024-11-05');
        self::assertSame(400, $old->getStatusCode(), 'a legacy header naming a version Logbook does not speak');
        self::assertSame(-32022, McpClient::body($old)['error']['code']);

        $noCapabilities = $mcp->raw('POST', (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => McpVersion::MODERN]],
        ]), ['MCP-Protocol-Version' => McpVersion::MODERN, 'Mcp-Method' => 'tools/list']);
        self::assertSame(400, $noCapabilities->getStatusCode());
        self::assertSame(-32602, McpClient::body($noCapabilities)['error']['code']);

        $headerOnly = $mcp->raw('POST', '{"jsonrpc":"2.0","id":9,"method":"tools/list"}', [
            'MCP-Protocol-Version' => McpVersion::MODERN,
            'Mcp-Method' => 'tools/list',
        ]);
        self::assertSame(400, $headerOnly->getStatusCode(), 'a modern header makes it modern: _meta is then required');
        self::assertSame(-32602, McpClient::body($headerOnly)['error']['code']);
    }

    public function testUnknownMethodsAreNotFoundInTheirEra(): void
    {
        $mcp = $this->client();

        $modern = $mcp->modern('logging/setLevel', ['level' => 'info']);
        self::assertSame(404, $modern->getStatusCode());
        self::assertSame(-32601, McpClient::body($modern)['error']['code']);
        McpClient::assertConforms(McpClient::body($modern)['error'], 'MethodNotFoundError');

        self::assertSame(404, $mcp->modern('ping')->getStatusCode(), 'ping is gone in 2026-07-28');

        $legacy = $mcp->legacy('resources/subscribe', ['uri' => 'logbook://me']);
        self::assertSame(200, $legacy->getStatusCode());
        self::assertSame(-32601, McpClient::body($legacy)['error']['code']);
    }

    public function testMalformedMessagesAndOtherHttpMethods(): void
    {
        $mcp = $this->client();

        $cases = [
            'not JSON' => ['{nope', -32700],
            'a batch' => ['[{"jsonrpc":"2.0","id":1,"method":"tools/list"}]', -32600],
            'no jsonrpc' => ['{"id":1,"method":"tools/list"}', -32600],
            'a null id' => ['{"jsonrpc":"2.0","id":null,"method":"tools/list"}', -32600],
            'params as a list' => ['{"jsonrpc":"2.0","id":1,"method":"tools/list","params":[1]}', -32600],
            'a response' => ['{"jsonrpc":"2.0","id":1,"result":{}}', -32600],
        ];
        foreach ($cases as $case => [$body, $code]) {
            $response = $mcp->raw('POST', $body);
            self::assertSame(400, $response->getStatusCode(), $case);
            self::assertSame($code, McpClient::body($response)['error']['code'], $case);
        }

        foreach (['GET', 'DELETE'] as $method) {
            $response = $mcp->raw($method, null);
            self::assertSame(405, $response->getStatusCode(), $method);
            self::assertSame('POST', $response->getHeaderLine('Allow'));
        }
    }

    public function testASessionIdFromAnOlderClientIsIgnored(): void
    {
        $mcp = $this->client();

        $response = $mcp->raw('POST', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', [
            'MCP-Protocol-Version' => '2025-11-25',
            'Mcp-Session-Id' => 'made-up',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Mcp-Session-Id'));
    }

    private function client(ApiScope $scope = ApiScope::Read): McpClient
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        return new McpClient($app, $this->apiKey($app, $owner, $scope));
    }
}
