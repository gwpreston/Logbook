<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

use RuntimeException;

/**
 * A Tracktor export Logbook won't read (spec.md §7.13 *The Tracktor
 * reader*): not JSON, nested too deep, password-protected, another version
 * or too many rows. Nothing has been read or written.
 */
final class TracktorRefused extends RuntimeException
{
    /**
     * @param array<string, int|string> $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
