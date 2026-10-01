<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Logbook\Kernel;
use Logbook\Service\Mcp\McpVersion;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Drives the MCP endpoint (spec.md §7.28) with a key, in either protocol
 * era, and checks every JSON-RPC answer against the specification's own
 * schema for that version (tests/Fixtures/mcp): a result against the
 * method's result type, an error against the error response. So every MCP
 * test is a conformance test too.
 */
final class McpClient
{
    private const string FIXTURES = '/tests/Fixtures/mcp/';
    /** Method → the result type in the schema. */
    private const array RESULTS = [
        'server/discover' => 'DiscoverResult',
        'initialize' => 'InitializeResult',
        'ping' => 'EmptyResult',
        'tools/list' => 'ListToolsResult',
        'tools/call' => 'CallToolResult',
        'resources/list' => 'ListResourcesResult',
        'resources/templates/list' => 'ListResourceTemplatesResult',
        'resources/read' => 'ReadResourceResult',
        'prompts/list' => 'ListPromptsResult',
        'prompts/get' => 'GetPromptResult',
    ];

    /** @var array<string, object> */
    private static array $schemas = [];

    public readonly string $address;
    private int $nextId = 1;

    /**
     * @param App<ContainerInterface> $app
     */
    public function __construct(
        private readonly App $app,
        private readonly ?string $token,
        private readonly string $path = '/mcp',
        ?string $address = null,
    ) {
        $this->address = $address ?? '198.51.100.' . random_int(1, 254) . '-' . bin2hex(random_bytes(4));
    }

    /**
     * A `2026-07-28` request, with its `_meta` and the headers that mirror it.
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $headers added, or replacing the mirrored ones
     */
    public function modern(string $method, array $params = [], array $headers = []): ResponseInterface
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => McpVersion::MODERN,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'LogbookTests', 'version' => '1.0.0'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ] + (is_array($params['_meta'] ?? null) ? $params['_meta'] : []);
        $mirrored = ['MCP-Protocol-Version' => McpVersion::MODERN, 'Mcp-Method' => $method];
        $name = $params['name'] ?? $params['uri'] ?? null;
        if (in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true) && is_string($name)) {
            $mirrored['Mcp-Name'] = preg_match('/^[\x21-\x7E]*$/', $name) === 1
                ? $name
                : '=?base64?' . base64_encode($name) . '?=';
        }

        return $this->send(
            self::message($this->nextId++, $method, $params),
            array_filter($headers + $mirrored, static fn (string $v): bool => $v !== ''),
            McpVersion::MODERN,
            $method,
        );
    }

    /**
     * A legacy (`initialize`-era) request, with `MCP-Protocol-Version` unless $version is null.
     *
     * @param array<string, mixed> $params
     */
    public function legacy(string $method, array $params = [], ?string $version = '2025-11-25'): ResponseInterface
    {
        return $this->send(
            self::message($this->nextId++, $method, $params),
            $version === null ? [] : ['MCP-Protocol-Version' => $version],
            '2025-11-25',
            $method,
        );
    }

    /**
     * Anything at all, unchecked.
     *
     * @param array<string, string> $headers
     */
    public function raw(string $httpMethod, ?string $body, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($httpMethod, $this->path, ['REMOTE_ADDR' => $this->address]);
        if ($this->token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->token);
        }
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request->getBody()->write($body);
            $request->getBody()->rewind();
        }

        return $this->app->handle($request);
    }

    /**
     * The decoded JSON-RPC answer.
     *
     * @return array<string, mixed>
     */
    public static function body(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($data);

        return $data;
    }

    /**
     * The result of a successful answer.
     *
     * @return array<string, mixed>
     */
    public static function result(ResponseInterface $response): array
    {
        $body = self::body($response);
        Assert::assertSame(200, $response->getStatusCode(), (string) json_encode($body));
        Assert::assertArrayHasKey('result', $body, (string) json_encode($body));
        Assert::assertIsArray($body['result']);

        return $body['result'];
    }

    /**
     * A tool call's structured content, after checking it is not an error.
     *
     * @return array<string, mixed>
     */
    public static function structured(ResponseInterface $response, bool $isError = false): array
    {
        $result = self::result($response);
        Assert::assertSame($isError, $result['isError'] ?? false, (string) json_encode($result));
        Assert::assertIsArray($result['structuredContent']);
        // The text block carries the same JSON, for clients that read only text.
        Assert::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));

        return $result['structuredContent'];
    }

    /**
     * Check a decoded message against one of the schema's types.
     */
    public static function assertConforms(mixed $message, string $type, string $version = McpVersion::MODERN): void
    {
        $schema = self::schema($version);
        $wrapped = json_decode((string) json_encode(['$ref' => '#/$defs/' . $type, '$defs' => $schema->{'$defs'}]));
        $document = is_object($message) ? $message : json_decode((string) json_encode($message));
        $validator = new Validator();
        $validator->validate($document, $wrapped, Constraint::CHECK_MODE_NORMAL);
        Assert::assertTrue(
            $validator->isValid(),
            sprintf(
                "%s (%s) does not match the specification:\n%s\n%s",
                $type,
                $version,
                json_encode($validator->getErrors(), JSON_PRETTY_PRINT),
                json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function message(int $id, string $method, array $params): string
    {
        return (string) json_encode(
            ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method] + ($params === [] ? [] : ['params' => $params]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $body, array $headers, string $schemaVersion, string $method): ResponseInterface
    {
        $response = $this->raw('POST', $body, $headers);
        $text = (string) $response->getBody();
        $response->getBody()->rewind();
        if ($text === '') {
            return $response;
        }
        Assert::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        // Decoded as objects, so an empty object stays one for the schema.
        $message = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsObject($message);
        if (isset($message->result)) {
            self::assertConforms($message->result, self::RESULTS[$method] ?? 'Result', $schemaVersion);
            if ($schemaVersion === McpVersion::MODERN) {
                Assert::assertSame('logbook', $message->result->_meta->{'io.modelcontextprotocol/serverInfo'}->name ?? null);
            }
        } else {
            self::assertConforms($message, 'JSONRPCErrorResponse', $schemaVersion);
        }

        return $response;
    }

    private static function schema(string $version): object
    {
        $file = McpVersion::isLegacy($version) ? '2025-11-25' : McpVersion::MODERN;
        if (!isset(self::$schemas[$file])) {
            $schema = json_decode((string) file_get_contents(Kernel::rootDir() . self::FIXTURES . $file . '/schema.json'));
            Assert::assertIsObject($schema);
            self::$schemas[$file] = $schema;
        }

        return self::$schemas[$file];
    }
}
