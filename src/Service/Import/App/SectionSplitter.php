<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App;

use Logbook\Support\Csv\CsvTooLong;

/**
 * Splits an app's CSV export into its sections (spec.md §7.13 *Importing
 * from another app*): a line holding only `## Name` starts a section, the
 * next record is its header and the records after it are its rows. Values
 * are read as RFC 4180 CSV (commas, quotes, newlines inside quotes), the
 * text as UTF-8 with or without a BOM, else Windows-1252, as §7.13 reads
 * any CSV.
 */
final class SectionSplitter
{
    /** Rows across every section of one file. */
    public const int MAX_ROWS = 20000;

    /**
     * @return AppFile|null null when the text has no `##` section at all
     * @throws CsvTooLong when the sections hold more than $maxRows rows together
     */
    public static function split(string $name, string $contents, int $maxRows = self::MAX_ROWS): ?AppFile
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        if (!mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        $contents = str_replace("\0", '', $contents);

        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            return null;
        }
        fwrite($stream, $contents);
        rewind($stream);

        /** @var array<string, array{header: list<string>|null, rows: list<array{line: int, cells: array<string, string>}>}> $sections */
        $sections = [];
        $current = null;
        $line = 0;
        $total = 0;
        try {
            while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $line++;
                // fgetcsv() gives [null] for a blank line.
                $values = array_map(static fn (?string $cell): string => trim($cell ?? ''), $cells);
                if (implode('', $values) === '') {
                    continue;
                }
                $marker = self::marker($values);
                if ($marker !== null) {
                    $current = $marker;
                    $sections[$current] ??= ['header' => null, 'rows' => []];
                    continue;
                }
                if ($current === null) {
                    continue; // text before the first section is not part of the export
                }
                if ($sections[$current]['header'] === null) {
                    $sections[$current]['header'] = array_map(
                        static fn (string $h, int $i): string => $h === '' ? '#' . ($i + 1) : $h,
                        $values,
                        array_keys($values),
                    );
                    continue;
                }
                if (++$total > $maxRows) {
                    throw new CsvTooLong($maxRows);
                }
                $row = [];
                foreach ($sections[$current]['header'] as $i => $column) {
                    $row[$column] = $values[$i] ?? '';
                }
                $sections[$current]['rows'][] = ['line' => $line, 'cells' => $row];
            }
        } finally {
            fclose($stream);
        }

        if ($sections === []) {
            return null;
        }
        $out = [];
        foreach ($sections as $sectionName => $section) {
            $out[$sectionName] = new AppSection($sectionName, $section['header'] ?? [], $section['rows']);
        }

        return new AppFile($name, $out);
    }

    /**
     * The section a record starts ("## Log" → "Log"), or null for any other
     * record.
     *
     * @param list<string> $values
     */
    private static function marker(array $values): ?string
    {
        $filled = array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
        if (count($filled) !== 1 || preg_match('/^##\s*(\S.*)$/u', $filled[0], $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }
}
