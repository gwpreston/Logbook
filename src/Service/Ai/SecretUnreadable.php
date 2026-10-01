<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use RuntimeException;

/**
 * A stored secret that cannot be used: sealed with another
 * `SESSION_SECRET`, or an `env:` variable that is not set (spec.md §7.25
 * *Secrets*). Nothing is sent on the connection.
 */
final class SecretUnreadable extends RuntimeException
{
    public function __construct(
        public readonly string $slot,
        /** The variable's name for an `env:` reference, else null. */
        public readonly ?string $variable = null,
    ) {
        parent::__construct($variable === null
            ? sprintf('The AI secret "%s" cannot be decrypted with this SESSION_SECRET.', $slot)
            : sprintf('The AI secret "%s" reads %s, which is not set.', $slot, $variable));
    }
}
