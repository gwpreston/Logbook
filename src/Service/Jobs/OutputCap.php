<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

/**
 * A run's stored output is at most 64 KB (spec.md §6 JobRun): longer
 * output keeps its first and last 32 KB, whole lines, with a note of how
 * many lines were left out between.
 */
final class OutputCap
{
    public const int LIMIT = 65536;
    private const int HALF = 32768;

    /**
     * @param list<string> $lines
     */
    public static function join(array $lines): string
    {
        $text = implode("\n", $lines);
        if (strlen($text) <= self::LIMIT) {
            return $text;
        }

        // Room for the note itself.
        $budget = self::HALF - 64;
        $head = [];
        $used = 0;
        foreach ($lines as $i => $line) {
            $size = strlen($line) + 1;
            if ($used + $size > $budget) {
                break;
            }
            $head[] = $line;
            $used += $size;
        }
        $tail = [];
        $used = 0;
        for ($i = count($lines) - 1; $i >= count($head); $i--) {
            $size = strlen($lines[$i]) + 1;
            if ($used + $size > $budget) {
                break;
            }
            array_unshift($tail, $lines[$i]);
            $used += $size;
        }
        $left = count($lines) - count($head) - count($tail);
        // A single huge line: keep its ends rather than nothing.
        if ($head === [] && $tail === []) {
            return mb_strcut($text, 0, $budget, 'UTF-8') . "\n… 1 lines left out …\n"
                . mb_strcut($text, strlen($text) - $budget, $budget, 'UTF-8');
        }

        return implode("\n", [...$head, sprintf('… %d lines left out …', $left), ...$tail]);
    }
}
