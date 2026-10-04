<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

/**
 * One `## Name` section of an app's export (spec.md §7.13 *Importing from
 * another app*): its header row and its rows, each cell under its header.
 */
final readonly class AppSection
{
    /**
     * @param list<string> $header
     * @param list<array{line: int, cells: array<string, string>}> $rows line = the record's number in the file
     */
    public function __construct(
        public string $name,
        public array $header,
        public array $rows,
    ) {
    }

    /**
     * The header whose name starts with $prefix ("Odo" finds "Odo (mi)"),
     * ignoring case; null when there is none.
     */
    public function column(string $prefix): ?string
    {
        foreach ($this->header as $name) {
            if (stripos($name, $prefix) === 0) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The words in brackets after a column's name ("Odo (mi)" → "mi"), or
     * null when the column has none.
     */
    public function unitOf(string $prefix): ?string
    {
        $column = $this->column($prefix);
        if ($column === null || preg_match('/\(([^)]+)\)\s*$/', $column, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }
}
