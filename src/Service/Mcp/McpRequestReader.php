<?php

declare(strict_types=1);

namespace Logbook\Service\Mcp;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads one POST to `/mcp` into a call (spec.md §7.28): JSON-RPC 2.0, one
 * message per request (no batches), and the protocol era it belongs to.
 *
 * A request is *modern* (`2026-07-28`) when its `_meta` names a protocol
 * version or its `MCP-Protocol-Version` header names that revision or a
 * later one. Then the per-request `_meta` fields are required, and the
 * `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` headers must match the
 * body (a `=?base64?…?=` value is decoded first). Anything else is a
 * *legacy* request, served as the version its header names (`2025-06-18`
 * without one); `initialize` is always legacy and negotiates its version.
 */
final class McpRequestReader
{
    public const string META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const string META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const string META_CLIENT = 'io.modelcontextprotocol/clientInfo';
    /** Methods whose `Mcp-Name` header mirrors a body field. */
    private const array NAMED = ['tools/call' => 'name', 'resources/read' => 'uri', 'prompts/get' => 'name'];

    /**
     * @throws McpError
     */
    public static function read(ServerRequestInterface $request): McpCall
    {
        $message = self::decode((string) $request->getBody());
        $id = $message['id'] ?? null;
        try {
            return self::call($request, $message);
        } catch (McpError $e) {
            throw $e->forId(is_int($id) || is_string($id) ? $id : null);
        }
    }

    /**
     * @param array<string, mixed> $message
     * @throws McpError
     */
    private static function call(ServerRequestInterface $request, array $message): McpCall
    {

        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || $method === '') {
            throw new McpError(McpError::INVALID_REQUEST, 'Send one JSON-RPC 2.0 request or notification.', 400);
        }
        $notification = !array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        if (!$notification && !is_int($id) && !is_string($id)) {
            throw new McpError(McpError::INVALID_REQUEST, 'A request id must be a string or an integer.', 400);
        }
        $id = is_int($id) || is_string($id) ? $id : null;
        $params = $message['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            throw new McpError(McpError::INVALID_REQUEST, '"params" must be an object.', 400);
        }
        /** @var array<string, mixed> $params */

        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $metaVersion = $meta[self::META_VERSION] ?? null;
        $header = trim($request->getHeaderLine('MCP-Protocol-Version'));

        if ($method === 'initialize') {
            $asked = $params['protocolVersion'] ?? null;
            $version = is_string($asked) && McpVersion::isLegacy($asked) ? $asked : McpVersion::LEGACY[0];

            return new McpCall($id, $method, $params, $version, false, $notification);
        }

        if ($metaVersion !== null || ($header !== '' && McpVersion::isModernEra($header))) {
            self::checkModern($request, $method, $params, $meta, $metaVersion, $header);

            return new McpCall($id, $method, $params, McpVersion::MODERN, true, $notification);
        }

        if ($header === '') {
            return new McpCall($id, $method, $params, McpVersion::LEGACY_UNSTATED, false, $notification);
        }
        if (!McpVersion::isLegacy($header)) {
            throw self::unsupported($header);
        }

        return new McpCall($id, $method, $params, $header, false, $notification);
    }

    /**
     * @return array<string, mixed>
     * @throws McpError
     */
    private static function decode(string $body): array
    {
        try {
            $message = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new McpError(McpError::PARSE_ERROR, 'The body is not valid JSON.', 400);
        }
        if (!is_array($message) || $message === []) {
            throw new McpError(McpError::INVALID_REQUEST, 'Send one JSON-RPC 2.0 request or notification.', 400);
        }
        if (array_is_list($message)) {
            throw new McpError(McpError::INVALID_REQUEST, 'Batches are not supported: send one message per request.', 400);
        }
        $out = [];
        foreach ($message as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<mixed> $meta
     * @throws McpError
     */
    private static function checkModern(
        ServerRequestInterface $request,
        string $method,
        array $params,
        array $meta,
        mixed $metaVersion,
        string $header,
    ): void {
        if (!is_string($metaVersion) || $metaVersion === '') {
            throw new McpError(
                McpError::INVALID_PARAMS,
                sprintf('"_meta" must name the protocol version ("%s").', self::META_VERSION),
                400,
            );
        }
        if ($header === '') {
            throw self::mismatch('The MCP-Protocol-Version header is missing.');
        }
        if (!self::isHeaderSafe($header) || $header !== $metaVersion) {
            throw self::mismatch(sprintf(
                'Header mismatch: MCP-Protocol-Version header value \'%s\' does not match body value \'%s\'.',
                self::printable($header),
                $metaVersion,
            ));
        }
        if ($metaVersion !== McpVersion::MODERN) {
            throw self::unsupported($metaVersion);
        }
        $capabilities = $meta[self::META_CAPABILITIES] ?? null;
        if (!is_array($capabilities) || ($capabilities !== [] && array_is_list($capabilities))) {
            throw new McpError(
                McpError::INVALID_PARAMS,
                sprintf('"_meta" must carry the client\'s capabilities ("%s").', self::META_CAPABILITIES),
                400,
            );
        }

        $mcpMethod = $request->getHeaderLine('Mcp-Method');
        if ($mcpMethod === '') {
            throw self::mismatch('The Mcp-Method header is missing.');
        }
        if (!self::isHeaderSafe($mcpMethod) || $mcpMethod !== $method) {
            throw self::mismatch(sprintf(
                'Header mismatch: Mcp-Method header value \'%s\' does not match body value \'%s\'.',
                self::printable($mcpMethod),
                $method,
            ));
        }

        $field = self::NAMED[$method] ?? null;
        if ($field === null) {
            return;
        }
        $body = $params[$field] ?? null;
        $name = $request->getHeaderLine('Mcp-Name');
        if ($name === '') {
            throw self::mismatch('The Mcp-Name header is missing.');
        }
        $decoded = self::decodeHeader($name);
        if ($decoded === null || !is_string($body) || $decoded !== $body) {
            throw self::mismatch(sprintf(
                'Header mismatch: Mcp-Name header value \'%s\' does not match body value \'%s\'.',
                self::printable($name),
                is_string($body) ? self::printable($body) : '',
            ));
        }
    }

    /**
     * A header value as sent, or decoded from the Base64 sentinel form;
     * null when it holds characters a header may not, or bad Base64.
     */
    private static function decodeHeader(string $value): ?string
    {
        if (!self::isHeaderSafe($value)) {
            return null;
        }
        if (str_starts_with($value, '=?base64?') && str_ends_with($value, '?=') && strlen($value) >= 11) {
            $decoded = base64_decode(substr($value, 9, -2), true);

            return $decoded === false || !mb_check_encoding($decoded, 'UTF-8') ? null : $decoded;
        }

        return $value;
    }

    private static function isHeaderSafe(string $value): bool
    {
        return preg_match('/^[\x20-\x7E\t]*$/', $value) === 1;
    }

    private static function printable(string $value): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7E]/', '?', $value), 0, 100);
    }

    private static function mismatch(string $message): McpError
    {
        return new McpError(McpError::HEADER_MISMATCH, $message, 400);
    }

    private static function unsupported(string $requested): McpError
    {
        return new McpError(McpError::UNSUPPORTED_VERSION, 'Unsupported protocol version', 400, [
            'supported' => McpVersion::supported(),
            'requested' => mb_substr($requested, 0, 40),
        ]);
    }
}
