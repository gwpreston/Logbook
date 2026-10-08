<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

/**
 * The `ETag` of a single-entry read (spec.md §7.20 *Phase 39*, #282): a hash
 * of the entity as it is stored, never of the response, so derived figures
 * (a fill-up's segment economy, which a neighbour's edit changes) and what
 * the viewer may see leave it alone. It changes exactly when the entry does.
 * Only `If-Match` uses it; `If-None-Match` is not supported.
 */
final class EntityTag
{
    public static function of(object $entity): string
    {
        return '"' . substr(hash('sha256', $entity::class . "\0" . serialize($entity)), 0, 32) . '"';
    }

    /**
     * Whether an `If-Match` header value names this tag (a list, `*`, or
     * weak tags compared strongly, as RFC 9110 asks for `If-Match`).
     */
    public static function matches(string $ifMatch, string $tag): bool
    {
        foreach (explode(',', $ifMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $tag) {
                return true;
            }
        }

        return false;
    }
}
