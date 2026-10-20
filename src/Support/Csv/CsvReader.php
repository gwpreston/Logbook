<?php

declare(strict_types=1);

namespace Logbook\Support\Csv;

/**
 * Reads an uploaded CSV file for import (spec.md §7.13): UTF-8 with or
 * without a byte-order mark, UTF-16 with one (#504; anything else that is
 * not valid UTF-8 is taken to be Windows-1252, what older spreadsheets
 * save), RFC 4180 quoting, and the delimiter — comma, semicolon or tab —
 * detected from the header row.
 */
final class CsvReader
{
    private const array DELIMITERS = [',', ';', "\t"];

    /** How files that are never text start: ZIP (a Fuelio backup, .xlsx, .ods), old .xls, PDF, images, gzip, 7z, RAR, SQLite. */
    private const array BINARY_SIGNATURES = [
        "PK\x03\x04", "PK\x05\x06", "\xD0\xCF\x11\xE0", '%PDF', "\x89PNG", "\xFF\xD8\xFF", 'GIF8', "\x1F\x8B",
        "7z\xBC\xAF", 'Rar!', "SQLite format 3\x00",
    ];
    /** Bytes looked at for NULs. */
    private const int SNIFF_BYTES = 8192;

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
        // UTF-16 with a byte-order mark (Excel's "Unicode text") becomes
        // UTF-8, the mark dropped (K10, #504); a damaged one is not read.
        if (self::utf16Encoding($contents) !== null) {
            $converted = self::fromUtf16($contents);
            if ($converted === null) {
                return null;
            }
            $contents = $converted;
        }
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
     * Whether the upload is plainly not text (K61): a known binary format,
     * NUL bytes near the start of a file without a UTF-16 byte-order mark
     * (UTF-16 text has them, and read() converts it), or UTF-16 that isn't
     * whole, such as a cut-off file (K125).
     */
    public static function isBinary(string $contents): bool
    {
        foreach (self::BINARY_SIGNATURES as $signature) {
            if (str_starts_with($contents, $signature)) {
                return true;
            }
        }
        if (self::utf16Encoding($contents) !== null) {
            return self::fromUtf16($contents) === null;
        }

        return str_contains(substr($contents, 0, self::SNIFF_BYTES), "\0");
    }

    /**
     * 'UTF-16LE' or 'UTF-16BE' when the file starts with that byte-order
     * mark, else null.
     */
    private static function utf16Encoding(string $contents): ?string
    {
        return match (true) {
            str_starts_with($contents, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($contents, "\xFE\xFF") => 'UTF-16BE',
            default => null,
        };
    }

    /**
     * UTF-16 text after its byte-order mark as UTF-8, or null when it isn't
     * whole (an odd byte at the end, a lone surrogate): converting back
     * must give the same bytes, which also covers the mbstring polyfill,
     * whose conversion fails instead (K125).
     */
    private static function fromUtf16(string $contents): ?string
    {
        $encoding = self::utf16Encoding($contents);
        if ($encoding === null) {
            return null;
        }
        $body = substr($contents, 2);
        if (strlen($body) % 2 !== 0) {
            return null;
        }
        $utf8 = @mb_convert_encoding($body, 'UTF-8', $encoding);
        if (!is_string($utf8) || @mb_convert_encoding($utf8, $encoding, 'UTF-8') !== $body) {
            return null;
        }

        return $utf8;
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
