<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use Logbook\Support\Config\AppSettings;

/**
 * The `ETag` of a single-entry read (spec.md §7.20 *Phase 39*, #282): a
 * keyed hash (HMAC-SHA256 with a key derived from `SESSION_SECRET`) of the
 * entity as it is stored, never of the response, so derived figures (a
 * fill-up's segment economy, which a neighbour's edit changes) and what the
 * viewer may see leave it alone. It changes exactly when the entry does.
 *
 * Keyed, because the stored entity holds what some viewers may not see (a
 * cost without ViewCosts): an unkeyed hash of it could be matched offline
 * against guessed amounts (security review, 2026-10-08). Only `If-Match`
 * uses it; `If-None-Match` is not supported.
 */
final readonly class EntityTag
{
    private string $key;

    public function __construct(AppSettings $settings)
    {
        $this->key = hash_hmac('sha256', 'logbook-entity-tag', $settings->sessionSecret, true);
    }

    public function of(object $entity): string
    {
        return '"' . substr(hash_hmac('sha256', $entity::class . "\0" . serialize($entity), $this->key), 0, 32) . '"';
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
