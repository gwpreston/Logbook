<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Number;

use Logbook\Support\Number\PercentDifference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentDifferenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function ratios(): iterable
    {
        yield 'ten percent more' => ['1.104090', 'more', 10];
        yield 'three percent less' => ['0.968', 'less', 3];
        yield 'rounds half up' => ['1.025', 'more', 3];
        yield 'exactly 1% is a difference' => ['0.99', 'less', 1];
        yield 'just under 1% more is the same' => ['1.009999', 'same', 0];
        yield 'just under 1% less is the same' => ['0.990001', 'same', 0];
        yield 'equal' => ['1', 'same', 0];
    }

    #[DataProvider('ratios')]
    public function testWordsARatio(string $ratio, string $direction, int $percent): void
    {
        $difference = PercentDifference::of($ratio);

        self::assertSame($direction, $difference->direction);
        self::assertSame($percent, $difference->percent);
        self::assertEqualsWithDelta($percent / 100, $difference->fraction(), 1e-9);
    }
}
