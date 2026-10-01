<?php

declare(strict_types=1);

namespace Logbook\Service\Mcp;

/**
 * One JSON-RPC message from a client, read and checked (spec.md §7.28):
 * its id (none for a notification), method and params, and the protocol
 * version it is served under. `modern` is the stateless `2026-07-28`
 * revision; otherwise it is a legacy, `initialize`-era request.
 */
final readonly class McpCall
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string|int|null $id,
        public string $method,
        public array $params,
        public string $version,
        public bool $modern,
        public bool $notification,
    ) {
    }

    public function string(string $name): ?string
    {
        $value = $this->params[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function object(string $name): array
    {
        $value = $this->params[$name] ?? null;
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw McpError::invalidParams(sprintf('"%s" must be an object.', $name));
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
