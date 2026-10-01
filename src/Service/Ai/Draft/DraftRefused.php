<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use RuntimeException;

/**
 * A draft that can't be added at all: its module is off, the vehicle is
 * not one the user may log on, it is archived, or the draft is closed.
 * The message is a translation key.
 */
final class DraftRefused extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct($key);
    }
}
