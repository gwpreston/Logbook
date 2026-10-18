<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

/**
 * Finds a value's evidence in OCR text, allowing for OCR's misreadings
 * (spec.md §7.27 *Checking values*; decided 2026-10-18, #498): both sides
 * lower-cased, their spaces removed and the usual swaps folded (O→0, l and
 * I→1, S→5, B→8), then the evidence must appear within an edit distance of
 * 2 per 20 characters, rounded up. Evidence under five characters must match exactly.
 */
final class EvidenceMatch
{
    private const array SWAPS = ['o' => '0', 'l' => '1', 'i' => '1', 's' => '5', 'b' => '8'];
    /** Shorter evidence than this gets no allowance: one edit would match almost anything. */
    private const int MIN_TOLERANT = 5;

    /**
     * @return list<string> the text folded, one character per entry
     */
    public static function fold(string $text): array
    {
        $chars = mb_str_split(mb_strtolower((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $text)));

        return array_map(static fn (string $c): string => self::SWAPS[$c] ?? $c, $chars);
    }

    public static function allowed(int $length): int
    {
        return $length < self::MIN_TOLERANT ? 0 : intdiv($length + 9, 10);
    }

    /**
     * Where the folded needle best matches in the folded haystack, as
     * [start, end) character offsets, or null when it is further than
     * allowed().
     *
     * @param list<string> $needle
     * @param list<string> $haystack
     * @return array{int, int}|null
     */
    public static function find(array $needle, array $haystack): ?array
    {
        $m = count($needle);
        if ($m === 0) {
            return null;
        }
        $exact = self::exact($needle, $haystack);
        if ($exact !== null) {
            return [$exact, $exact + $m];
        }
        $allowed = self::allowed($m);
        if ($allowed === 0 || $haystack === []) {
            return null;
        }

        // Sellers' algorithm: the edit distance of the needle against any
        // substring of the haystack ending at each position, carrying where
        // that substring starts. A column of (distance, start) pairs.
        $dist = range(0, $m);
        $start = array_fill(0, $m + 1, 0);
        $best = null;
        foreach ($haystack as $j => $char) {
            $prevDist = $dist[0];
            $prevStart = $start[0];
            $dist[0] = 0;
            $start[0] = $j + 1;
            for ($i = 1; $i <= $m; $i++) {
                $diagonal = $prevDist + ($needle[$i - 1] === $char ? 0 : 1);
                $diagonalStart = $prevStart;
                $prevDist = $dist[$i];
                $prevStart = $start[$i];
                // Up (skip a needle character) or left (skip a haystack character).
                $up = $dist[$i - 1] + 1;
                $left = $dist[$i] + 1;
                if ($diagonal <= $up && $diagonal <= $left) {
                    $dist[$i] = $diagonal;
                    $start[$i] = $diagonalStart;
                } elseif ($up <= $left) {
                    $dist[$i] = $up;
                    $start[$i] = $start[$i - 1];
                } else {
                    $dist[$i] = $left;
                }
            }
            if ($dist[$m] <= $allowed && ($best === null || $dist[$m] < $best[2])) {
                $best = [$start[$m], $j + 1, $dist[$m]];
                if ($dist[$m] === 0) {
                    break;
                }
            }
        }

        return $best === null ? null : [$best[0], $best[1]];
    }

    /**
     * @param list<string> $needle
     * @param list<string> $haystack
     */
    private static function exact(array $needle, array $haystack): ?int
    {
        $pos = strpos(implode('', $haystack), implode('', $needle));

        return $pos === false ? null : mb_strlen(substr(implode('', $haystack), 0, $pos));
    }
}
