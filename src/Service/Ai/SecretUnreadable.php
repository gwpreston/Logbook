<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use RuntimeException;

/**
 * A stored secret that cannot be used: sealed with another
 * `SESSION_SECRET`, or an `env:` variable that is not set (spec.md §7.25
 * *Secrets*; notification secrets too, §7.11). Nothing is sent with it.
 */
final class SecretUnreadable extends RuntimeException
{
    public function __construct(
        public readonly string $slot,
        /** The variable's name for an `env:` reference, else null. */
        public readonly ?string $variable = null,
    ) {
        parent::__construct($variable === null
            ? sprintf('The secret "%s" cannot be decrypted with this SESSION_SECRET.', $slot)
            : sprintf('The secret "%s" reads %s, which is not set.', $slot, $variable));
    }
}
