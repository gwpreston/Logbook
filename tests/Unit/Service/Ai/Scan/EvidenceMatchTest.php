<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Scan\EvidenceMatch;
use PHPUnit\Framework\TestCase;

/**
 * K89: the pigeonhole filter in front of Sellers' algorithm finds what the
 * full algorithm (Phase 53.2's) found, and does it fast on a whole page.
 */
final class EvidenceMatchTest extends TestCase
{
    public function testItAgreesWithTheFullAlgorithmOnSeededRandomInput(): void
    {
        mt_srand(5303);
        $alphabet = str_split('abcdefgh0123456789');
        $near = 0;
        for ($case = 0; $case < 400; $case++) {
            $haystack = self::randomChars($alphabet, mt_rand(0, 300));
            $needle = match ($case % 3) {
                0 => self::randomChars($alphabet, mt_rand(1, 40)),
                1 => self::edited(self::slice($haystack, 5, 40), mt_rand(0, 3), $alphabet),
                default => self::edited(self::slice($haystack, 20, 20), mt_rand(1, 3), $alphabet),
            };

            $expected = self::reference($needle, $haystack);
            $found = EvidenceMatch::find($needle, $haystack);

            self::assertSame($expected === null, $found === null, "case $case: " . implode('', $needle));
            if ($expected !== null) {
                $near++;
                self::assertNotNull($found);
                self::assertSame(
                    $expected,
                    self::distance($needle, array_slice($haystack, $found[0], $found[1] - $found[0])),
                    "case $case: the same best distance",
                );
            }
        }
        self::assertGreaterThan(100, $near, 'the generator reaches matches, near misses included');
    }

    public function testEvidenceNotOnAFullPageIsRuledOutQuickly(): void
    {
        mt_srand(17);
        $words = ['TOTAL', 'LITRES', 'PUMP', 'UNLEADED', 'VAT', '12.34', '56.78', 'THANK', 'YOU', 'CARD', 'SHORELINE'];
        $page = '';
        while (strlen($page) < 17000) {
            $page .= $words[mt_rand(0, count($words) - 1)] . ' ';
        }
        $haystack = EvidenceMatch::fold($page);
        $needle = EvidenceMatch::fold(str_repeat('QZXJKW ', 50));
        self::assertGreaterThanOrEqual(300, count($needle));

        $from = hrtime(true);
        $found = EvidenceMatch::find($needle, $haystack);
        $ms = (hrtime(true) - $from) / 1e6;

        self::assertNull($found);
        self::assertLessThan(150.0, $ms, 'took ' . round($ms) . ' ms');
    }

    /**
     * @param list<string> $alphabet
     * @return list<string>
     */
    private static function randomChars(array $alphabet, int $length): array
    {
        $out = [];
        for ($i = 0; $i < $length; $i++) {
            $out[] = $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        return $out;
    }

    /**
     * A stretch of $haystack of up to $length characters, from a random place
     * (at least $min of them when it can).
     *
     * @param list<string> $haystack
     * @return list<string>
     */
    private static function slice(array $haystack, int $min, int $length): array
    {
        if (count($haystack) <= $min) {
            return ['a', 'b', 'c', 'd', 'e', 'f'];
        }
        $size = min(count($haystack), mt_rand($min, $length));

        return array_slice($haystack, mt_rand(0, count($haystack) - $size), $size);
    }

    /**
     * @param list<string> $chars
     * @param list<string> $alphabet
     * @return list<string>
     */
    private static function edited(array $chars, int $edits, array $alphabet): array
    {
        for ($e = 0; $e < $edits && $chars !== []; $e++) {
            $at = mt_rand(0, count($chars) - 1);
            switch (mt_rand(0, 2)) {
                case 0:
                    $chars[$at] = $alphabet[mt_rand(0, count($alphabet) - 1)];
                    break;
                case 1:
                    array_splice($chars, $at, 1);
                    break;
                default:
                    array_splice($chars, $at, 0, [$alphabet[mt_rand(0, count($alphabet) - 1)]]);
            }
        }

        return array_values($chars);
    }

    /**
     * The edit distance of two whole character lists.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function distance(array $a, array $b): int
    {
        $row = range(0, count($b));
        foreach ($a as $i => $x) {
            $prev = $row[0];
            $row[0] = $i + 1;
            foreach ($b as $j => $y) {
                $tmp = $row[$j + 1];
                $row[$j + 1] = min($prev + ($x === $y ? 0 : 1), $row[$j] + 1, $row[$j + 1] + 1);
                $prev = $tmp;
            }
        }

        return $row[count($b)];
    }

    /**
     * Phase 53.2's algorithm, whole (git show master:src/Service/Ai/Scan/EvidenceMatch.php):
     * the best distance of the needle against any substring, or null when above the allowance.
     *
     * @param list<string> $needle
     * @param list<string> $haystack
     */
    private static function reference(array $needle, array $haystack): ?int
    {
        $m = count($needle);
        if ($m === 0) {
            return null;
        }
        if (str_contains(implode('', $haystack), implode('', $needle))) {
            return 0;
        }
        $allowed = EvidenceMatch::allowed($m);
        if ($allowed === 0 || $haystack === []) {
            return null;
        }
        $dist = range(0, $m);
        $best = null;
        foreach ($haystack as $char) {
            $prev = $dist[0];
            $dist[0] = 0;
            for ($i = 1; $i <= $m; $i++) {
                $diagonal = $prev + ($needle[$i - 1] === $char ? 0 : 1);
                $prev = $dist[$i];
                $dist[$i] = min($diagonal, $dist[$i - 1] + 1, $dist[$i] + 1);
            }
            if ($dist[$m] <= $allowed && ($best === null || $dist[$m] < $best)) {
                $best = $dist[$m];
            }
        }

        return $best;
    }
}
