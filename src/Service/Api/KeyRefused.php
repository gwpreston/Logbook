<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use RuntimeException;

/**
 * An API key turned away at the door (spec.md §7.20): missing, bad, or from
 * an address that is throttled. The API answers it as problem details, the
 * MCP endpoint (§7.28) as a JSON-RPC error; both send the status and headers.
 */
final class KeyRefused extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        string $detail,
        public readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }
}
