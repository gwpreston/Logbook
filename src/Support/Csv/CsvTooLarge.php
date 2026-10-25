<?php

declare(strict_types=1);

namespace Logbook\Support\Csv;

use RuntimeException;

/**
 * A CSV file whose rows would take more memory than PHP's `memory_limit`
 * leaves for reading it (K129).
 */
final class CsvTooLarge extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The CSV file is too large to read within the memory limit.');
    }
}
