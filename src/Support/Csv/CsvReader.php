<?php

declare(strict_types=1);

namespace Logbook\Support\Csv;

use Closure;
use RuntimeException;

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

    /** Bytes converted or checked at a time. */
    private const int CHUNK = 1024 * 1024;

    /** Rows between memory checks. */
    private const int BUDGET_EVERY = 64;

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
     * @throws CsvTooLarge when its rows would take more than $budget bytes of memory
     */
    public static function read(string $contents, int $maxRows, ?int $budget = null): ?self
    {
        $stream = self::temp();
        fwrite($stream, $contents);

        return self::fromStream($stream, $maxRows, $budget);
    }

    /**
     * A file on disk, read a row at a time (K129): what is held is the
     * rows, not the file's text as well.
     *
     * @return self|null null when the file has no header row or can't be opened
     * @throws CsvTooLong when it has more than $maxRows data rows
     * @throws CsvTooLarge when its rows would take more than $budget bytes of memory
     */
    public static function fromFile(string $path, int $maxRows, ?int $budget = null): ?self
    {
        $stream = @fopen($path, 'rb');

        return $stream === false ? null : self::fromStream($stream, $maxRows, $budget);
    }

    /**
     * The rows of a stream, which is closed. Re-encoding and NUL removal
     * go a chunk at a time into a temporary file, so the text is never in
     * memory whole. $budget defaults to half of `memory_limit`, leaving the
     * rest for the steps after reading.
     *
     * @param resource $stream
     * @return self|null null when the file has no header row
     * @throws CsvTooLong when it has more than $maxRows data rows
     * @throws CsvTooLarge when its rows would take more than $budget bytes of memory
     */
    private static function fromStream($stream, int $maxRows, ?int $budget): ?self
    {
        $budget ??= self::budget();
        $text = self::text($stream);
        if ($text === null) {
            return null;
        }
        try {
            $start = (int) ftell($text);
            $firstLine = false;
            while ($firstLine === false && ($line = fgets($text)) !== false) {
                $firstLine = strtok($line, "\r\n");
            }
            if ($firstLine === false || trim($firstLine) === '') {
                return null;
            }
            $delimiter = self::delimiter($firstLine);
            fseek($text, $start);

            $header = null;
            $rows = [];
            $line = 0;
            while (($cells = fgetcsv($text, null, $delimiter, '"', '')) !== false) {
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
                if ($budget !== null && $line % self::BUDGET_EVERY === 0 && memory_get_usage(true) > $budget) {
                    throw new CsvTooLarge();
                }
                $rows[] = ['line' => $line, 'cells' => $values];
            }
        } finally {
            fclose($text);
        }

        return $header === null ? null : new self($header, $rows);
    }

    /**
     * The stream as UTF-8 without NULs, positioned after any byte-order
     * mark: UTF-16 with a mark converted (null when it isn't whole, K125),
     * text that isn't valid UTF-8 read as Windows-1252. The given stream
     * is closed when a converted copy replaces it, or when null is given.
     *
     * @param resource $stream
     * @return resource|null
     */
    private static function text($stream)
    {
        rewind($stream);
        $encoding = self::utf16Encoding((string) fread($stream, 2));
        if ($encoding !== null) {
            $converted = self::temp();
            $whole = self::chunks($stream, 2, self::utf16Converter($encoding), $converted);
            fclose($stream);
            if (!$whole) {
                fclose($converted);

                return null;
            }
            $stream = $converted;
        }

        rewind($stream);
        $start = fread($stream, 3) === "\xEF\xBB\xBF" ? 3 : 0;
        fseek($stream, $start);
        $carry = '';
        $valid = true;
        $nul = false;
        while (($chunk = fread($stream, self::CHUNK)) !== false && $chunk !== '') {
            $nul = $nul || str_contains($chunk, "\0");
            [$whole, $carry] = self::utf8Split($carry . $chunk);
            $valid = $valid && mb_check_encoding($whole, 'UTF-8');
        }
        $valid = $valid && ($carry === '' || mb_check_encoding($carry, 'UTF-8'));
        if ($valid && !$nul) {
            fseek($stream, $start);

            return $stream;
        }

        // Windows-1252 is one byte a character, so each chunk converts on its own.
        $cleaned = self::temp();
        self::chunks($stream, $start, static fn (string $chunk, bool $last): array => [
            str_replace("\0", '', $valid ? $chunk : mb_convert_encoding($chunk, 'UTF-8', 'Windows-1252')),
            '',
        ], $cleaned);
        fclose($stream);
        rewind($cleaned);

        return $cleaned;
    }

    /**
     * Copy $from (from byte $offset) into $to through $convert, which gets
     * each chunk (after what it held back from the last) and whether it is
     * the last, and gives the text to write and what to hold back, or null
     * when the input is bad.
     *
     * @param resource $from
     * @param resource $to
     * @param Closure(string, bool): (array{string, string}|null) $convert
     * @return bool false when $convert refused the input
     */
    private static function chunks($from, int $offset, Closure $convert, $to): bool
    {
        fseek($from, $offset);
        $carry = '';
        do {
            $chunk = (string) fread($from, self::CHUNK);
            $last = $chunk === '' || feof($from);
            $result = $convert($carry . $chunk, $last);
            if ($result === null) {
                return false;
            }
            fwrite($to, $result[0]);
            $carry = $result[1];
        } while (!$last);

        return $carry === '';
    }

    /**
     * Converts UTF-16 chunks to UTF-8, holding back an odd byte or a lead
     * surrogate cut off from its pair; a chunk that doesn't convert back to
     * the same bytes is refused (K125).
     *
     * @return Closure(string, bool): (array{string, string}|null)
     */
    private static function utf16Converter(string $encoding): Closure
    {
        return static function (string $bytes, bool $last) use ($encoding): ?array {
            $keep = strlen($bytes) % 2;
            if (!$last && strlen($bytes) >= 2 + $keep) {
                $unit = substr($bytes, -2 - $keep, 2);
                $high = $encoding === 'UTF-16LE' ? ord($unit[1]) : ord($unit[0]);
                if ($high >= 0xD8 && $high <= 0xDB) {
                    $keep += 2;
                }
            }
            if ($last && $keep !== 0) {
                return null;
            }
            $body = substr($bytes, 0, strlen($bytes) - $keep);
            $utf8 = @mb_convert_encoding($body, 'UTF-8', $encoding);
            if (!is_string($utf8) || @mb_convert_encoding($utf8, $encoding, 'UTF-8') !== $body) {
                return null;
            }

            return [$utf8, substr($bytes, strlen($body))];
        };
    }

    /**
     * Text split into what ends on a whole UTF-8 character and the
     * incomplete sequence (at most 3 bytes) cut off at its end.
     *
     * @return array{string, string}
     */
    private static function utf8Split(string $text): array
    {
        $length = strlen($text);
        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($text[$length - $back]);
            if (($byte & 0xC0) !== 0x80) {
                // A lead byte: is its sequence whole?
                $needs = match (true) {
                    $byte >= 0xF0 => 4,
                    $byte >= 0xE0 => 3,
                    $byte >= 0xC0 => 2,
                    default => 1,
                };

                return $needs > $back
                    ? [substr($text, 0, $length - $back), substr($text, $length - $back)]
                    : [$text, ''];
            }
        }

        return [$text, ''];
    }

    /**
     * Half of `memory_limit` in bytes, or null when there is no limit.
     */
    private static function budget(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return null;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $bytes > 0 ? intdiv($bytes, 2) : null;
    }

    /**
     * @return resource kept in memory up to one chunk, then on disk
     */
    private static function temp()
    {
        $stream = fopen('php://temp/maxmemory:' . self::CHUNK, 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No temporary stream for the CSV file.');
        }

        return $stream;
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
     * isBinary() for a file on disk: only its start is read, and for UTF-16
     * the whole file a chunk at a time (K129).
     */
    public static function isBinaryFile(string $path): bool
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return true;
        }
        try {
            $head = (string) fread($stream, self::SNIFF_BYTES);
            $encoding = self::utf16Encoding($head);
            if ($encoding === null) {
                return self::isBinary($head);
            }
            $sink = self::temp();
            $whole = self::chunks($stream, 2, self::utf16Converter($encoding), $sink);
            fclose($sink);

            return !$whole;
        } finally {
            fclose($stream);
        }
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
     * must give the same bytes (K125).
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
