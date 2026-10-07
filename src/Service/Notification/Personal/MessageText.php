<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Closure;

/**
 * Fitting a message into a service's limit (spec.md §7.11): counted in
 * Unicode code points, cut between lines with a final "…and N more" line
 * and the link, never mid-line and never over the limit. A single line
 * that can't fit is cut with an ellipsis.
 */
final class MessageText
{
    public const string ELLIPSIS = '…';

    /** Code points, as every service counts them. */
    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * $text cut to at most $limit code points, ending in an ellipsis when cut.
     */
    public static function cut(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }
        if (self::length($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . self::ELLIPSIS;
    }

    /**
     * The lines joined with newlines, then a blank line and $tail (the
     * link) when given, within $limit. When they don't all fit, as many
     * leading lines as fit are kept, followed by `$more(n)` for the n
     * non-empty lines left out.
     *
     * @param list<string> $lines
     * @param Closure(int): string $more
     */
    public static function fit(array $lines, int $limit, ?string $tail, Closure $more): string
    {
        $lines = self::trimBlankEnds($lines);
        $suffix = $tail === null || $tail === '' ? '' : "\n\n" . $tail;
        $whole = implode("\n", $lines) . $suffix;
        if (self::length($whole) <= $limit) {
            return $whole;
        }
        if (self::length($suffix) >= $limit) {
            // Not even the link fits: the text alone, cut.
            return self::cut(implode("\n", $lines), $limit);
        }

        // Prefix lengths: $prefix[$k] is the length of the first $k lines joined.
        $prefix = [0];
        foreach ($lines as $i => $line) {
            $prefix[] = $prefix[$i] + self::length($line) + ($i > 0 ? 1 : 0);
        }
        $nonEmpty = [0];
        foreach ($lines as $i => $line) {
            $nonEmpty[] = $nonEmpty[$i] + (trim($line) === '' ? 0 : 1);
        }
        $total = count($lines);

        for ($k = $total - 1; $k >= 1; $k--) {
            $kept = self::trimBlankEnds(array_slice($lines, 0, $k));
            $left = $nonEmpty[$total] - $nonEmpty[$k];
            $tailText = ($left > 0 ? "\n" . $more($left) : '') . $suffix;
            $keptLength = $prefix[count($kept)];
            if ($keptLength + self::length($tailText) <= $limit) {
                return implode("\n", $kept) . $tailText;
            }
        }

        // Not even the first line fits beside the rest: cut the first line.
        $left = $nonEmpty[$total] - ($total > 0 && trim($lines[0]) !== '' ? 1 : 0);
        $tailText = ($left > 0 ? "\n" . $more($left) : '') . $suffix;
        $room = $limit - self::length($tailText);
        if ($room < 2) {
            return self::cut(($lines[0] ?? '') . $suffix, $limit);
        }

        return self::cut($lines[0] ?? '', $room) . $tailText;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function trimBlankEnds(array $lines): array
    {
        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
