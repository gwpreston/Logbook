<?php

declare(strict_types=1);

namespace Logbook\Support\Csv;

/**
 * Reads an uploaded CSV file for import (spec.md §7.13): UTF-8 with or
 * without a byte-order mark (anything that is not valid UTF-8 is taken to be
 * Windows-1252, what older spreadsheets save), RFC 4180 quoting, and the
 * delimiter — comma, semicolon or tab — detected from the header row.
 */
final class CsvReader
{
    private const array DELIMITERS = [',', ';', "\t"];

    private function __construct(
        /** @var list<string> */
        public readonly array $header,
        /** @var list<array{line: int, cells: list<string>}> data rows with their row number as a spreadsheet shows it */
        public readonly array $rows,
    ) {
    }

    /**
     * @return self|null null when the file has no header row
     * @throws CsvTooLong when it has more than $maxRows data rows
     */
    public static function read(string $contents, int $maxRows): ?self
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        if (!mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        $contents = str_replace("\0", '', $contents);

        $firstLine = strtok($contents, "\r\n");
        if ($firstLine === false || trim($firstLine) === '') {
            return null;
        }
        $delimiter = self::delimiter($firstLine);

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            return null;
        }
        fwrite($stream, $contents);
        rewind($stream);

        $header = null;
        $rows = [];
        $line = 0;
        try {
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $line++;
                // fgetcsv() gives [null] for a blank line.
                $values = array_map(static fn (?string $cell): string => $cell ?? '', $cells);
                if (implode('', array_map(trim(...), $values)) === '') {
                    continue;
                }
                if ($header === null) {
                    $header = array_map(
                        static fn (string $name, int $i): string => trim($name) === '' ? '#' . ($i + 1) : trim($name),
                        $values,
                        array_keys($values),
                    );
                    continue;
                }
                if (count($rows) >= $maxRows) {
                    throw new CsvTooLong($maxRows);
                }
                $rows[] = ['line' => $line, 'cells' => $values];
            }
        } finally {
            fclose($stream);
        }

        return $header === null ? null : new self($header, $rows);
    }

    /**
     * The candidate that splits the header row into the most columns
     * (outside quotes); comma when there is only one column.
     */
    private static function delimiter(string $headerLine): string
    {
        $unquoted = preg_replace('/"[^"]*"/', '', $headerLine) ?? $headerLine;
        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($unquoted, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
