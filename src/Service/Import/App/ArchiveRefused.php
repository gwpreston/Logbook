<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use RuntimeException;

/**
 * An archive that is refused before anything in it is read (spec.md §7.13
 * *ZIP safety*), with the message to show.
 */
final class ArchiveRefused extends RuntimeException
{
    /**
     * @param array<string, int|string> $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
