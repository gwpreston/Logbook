<?php

declare(strict_types=1);

namespace Logbook\Service\Mcp;

use RuntimeException;

/**
 * A JSON-RPC error answer (spec.md §7.28), with the HTTP status the
 * transport asks for: 400 for a malformed request, a header mismatch or an
 * unsupported version, 404 for an unknown method in the modern protocol,
 * 200 otherwise.
 */
final class McpError extends RuntimeException
{
    public const int PARSE_ERROR = -32700;
    public const int INVALID_REQUEST = -32600;
    public const int METHOD_NOT_FOUND = -32601;
    public const int INVALID_PARAMS = -32602;
    public const int INTERNAL_ERROR = -32603;
    public const int HEADER_MISMATCH = -32020;
    public const int UNSUPPORTED_VERSION = -32022;
    /** Resource not found before 2026-07-28; the modern protocol uses INVALID_PARAMS. */
    public const int LEGACY_RESOURCE_NOT_FOUND = -32002;
    /*
     * Logbook's own, outside the JSON-RPC reserved range: refused before the
     * message is read, with the HTTP status of the same number.
     */
    public const int UNAUTHORIZED = -31401;
    public const int FORBIDDEN_ORIGIN = -31403;
    public const int THROTTLED = -31429;

    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly int $status = 200,
        public readonly ?array $data = null,
        /** The request's id, once it could be read. */
        public readonly string|int|null $id = null,
    ) {
        parent::__construct($message);
    }

    public function forId(string|int|null $id): self
    {
        return new self($this->rpcCode, $this->getMessage(), $this->status, $this->data, $id);
    }

    public static function invalidParams(string $message): self
    {
        return new self(self::INVALID_PARAMS, $message);
    }
}
