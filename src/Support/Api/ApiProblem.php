<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use RuntimeException;

/**
 * An API error as RFC 9457 problem details (spec.md §7.20): the HTTP status,
 * a stable `code` clients can branch on, English `detail`, and for
 * validation the errors per field. Thrown anywhere under the API group;
 * ApiErrorMiddleware renders it.
 */
final class ApiProblem extends RuntimeException
{
    /**
     * @param array<string, array{key: string, message: string}> $errors per field
     * @param array<string, string> $headers extra response headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        string $detail,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    public static function notFound(string $detail = 'Nothing was found at this address.'): self
    {
        return new self(404, 'not_found', $detail);
    }

    public static function invalidParameter(string $name, string $detail): self
    {
        return new self(400, 'invalid_parameter', sprintf('Query parameter "%s": %s', $name, $detail));
    }

    public function detail(): string
    {
        return $this->getMessage();
    }
}
