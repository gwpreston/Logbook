<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification\Digest;

use Logbook\Service\Notification\Digest\DigestWording;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The digest's comparison wording (spec.md §7.11 *The monthly briefing*):
 * "about N% more/less", "about the same" within ±5%, nothing without an average.
 */
final class DigestWordingTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null, string, int}>
     */
    public static function trends(): iterable
    {
        yield 'exactly five percent more is the same' => ['105', '100', 'same', 5];
        yield 'exactly five percent less is the same' => ['95', '100', 'same', 5];
        yield 'six percent more' => ['106', '100', 'more', 6];
        yield 'six percent less' => ['94', '100', 'less', 6];
        yield 'equal' => ['100', '100', 'same', 0];
        yield 'twice the average' => ['200', '100', 'more', 100];
        yield 'decimal figures' => ['812.500', '738.250', 'more', 10];
        yield 'no value' => [null, '100', 'none', 0];
        yield 'no average' => ['100', null, 'none', 0];
        yield 'a zero average' => ['100', '0', 'none', 0];
        yield 'a negative average' => ['100', '-5', 'none', 0];
        yield 'a zero value against an average' => ['0', '100', 'less', 100];
    }

    #[DataProvider('trends')]
    public function testTrendFollowsTheFiveBoundary(?string $value, ?string $average, string $trend, int $percent): void
    {
        self::assertSame(['trend' => $trend, 'percent' => $percent], DigestWording::trend($value, $average));
    }
}
