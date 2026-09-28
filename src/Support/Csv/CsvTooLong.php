<?php

declare(strict_types=1);

namespace Logbook\Support\Csv;

use RuntimeException;

/**
 * A CSV file with more data rows than an import accepts.
 */
final class CsvTooLong extends RuntimeException
{
    public function __construct(public readonly int $maxRows)
    {
        parent::__construct(sprintf('The CSV file has more than %d rows.', $maxRows));
    }
}
