<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The `include` query parameter (spec.md §7.20, Phase 48.2): a comma-separated
 * list of extras to add beside a figure. Only `working` exists: how the
 * figure was worked out (spec.md §8 *Show the working*). Anything else is
 * a 400, so a typo isn't read as "nothing extra".
 */
final class IncludeParam
{
    /** The extras a request may ask for. */
    private const array KNOWN = ['working'];

    /**
     * @throws ApiProblem
     */
    public static function working(ServerRequestInterface $request): bool
    {
        $raw = $request->getQueryParams()['include'] ?? null;
        if ($raw === null) {
            return false;
        }
        if (!is_string($raw)) {
            throw ApiProblem::invalidParameter('include', 'a comma-separated list: working.');
        }
        $wanted = [];
        foreach (explode(',', $raw) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (!in_array($name, self::KNOWN, true)) {
                throw ApiProblem::invalidParameter('include', 'only "working" can be included.');
            }
            $wanted[$name] = true;
        }

        return isset($wanted['working']);
    }
}
