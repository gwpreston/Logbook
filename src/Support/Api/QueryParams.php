<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use BackedEnum;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the list filters (spec.md §7.20): codes, flags and search text.
 * A value that can't be read answers 400 `invalid_parameter`; an absent
 * one is null (or false for a flag).
 */
final class QueryParams
{
    /**
     * @template E of BackedEnum
     * @param class-string<E> $enum
     * @return E|null
     * @throws ApiProblem
     */
    public static function code(ServerRequestInterface $request, string $name, string $enum): ?BackedEnum
    {
        $raw = $request->getQueryParams()[$name] ?? null;
        if ($raw === null) {
            return null;
        }
        $value = is_string($raw) ? $enum::tryFrom($raw) : null;
        if ($value === null) {
            throw ApiProblem::invalidParameter($name, 'one of ' . implode(', ', array_map(
                static fn (BackedEnum $case): string => (string) $case->value,
                $enum::cases(),
            )) . '.');
        }

        return $value;
    }

    /**
     * `1` / `true` or `0` / `false`.
     *
     * @throws ApiProblem
     */
    public static function flag(ServerRequestInterface $request, string $name): bool
    {
        $raw = $request->getQueryParams()[$name] ?? null;

        return match ($raw) {
            null, '0', 'false' => false,
            '1', 'true' => true,
            default => throw ApiProblem::invalidParameter($name, '1 or 0 (true or false).'),
        };
    }

    /**
     * Search text, trimmed and cut to `$max` characters; null when empty.
     *
     * @throws ApiProblem
     */
    public static function text(ServerRequestInterface $request, string $name, int $max = 100): ?string
    {
        $raw = $request->getQueryParams()[$name] ?? null;
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            throw ApiProblem::invalidParameter($name, 'text.');
        }
        $text = mb_substr(trim($raw), 0, $max);

        return $text === '' ? null : $text;
    }
}
