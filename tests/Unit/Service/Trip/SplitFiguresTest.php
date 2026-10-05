<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Trip;

use Logbook\Service\Trip\MileageSplit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The *Business and private* card's percents (spec.md §7.22, Phase 33.3):
 * whole percents of the distance driven that add up to 100, business
 * rounded half up and private the rest; none without a split to show.
 */
final class SplitFiguresTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?int, ?int}>
     */
    public static function cases(): iterable
    {
        yield 'the worked example' => ['3100', '12400', 25, 75];
        // 12.5 / 87.5: rounding each would give 13 + 88 = 101.
        yield 'a half rounds business up, private takes the rest' => ['125', '1000', 13, 87];
        yield 'just under a half rounds down' => ['124.999', '1000', 12, 88];
        yield 'no business' => ['0', '1000', 0, 100];
        yield 'all business' => ['1000', '1000', 100, 0];
        yield 'nothing measurably driven' => ['0', null, null, null];
        yield 'driven is zero' => ['0', '0', null, null];
        yield 'business over the total' => ['1200', '1000', null, null];
    }

    #[DataProvider('cases')]
    public function testPercentsAddUpTo100(string $business, ?string $total, ?int $businessPercent, ?int $privatePercent): void
    {
        $split = MileageSplit::split($business, $total);

        self::assertSame($businessPercent, $split->businessPercent());
        self::assertSame($privatePercent, $split->privatePercent());
        if ($businessPercent !== null) {
            self::assertSame(100, $businessPercent + $privatePercent);
        }
    }

    public function testATotalOnlyViewerGetsNoPercents(): void
    {
        $split = MileageSplit::split('3100', '12400', totalOnly: true);

        self::assertNull($split->businessPercent());
        self::assertNull($split->privatePercent());
    }
}
