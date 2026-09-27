<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Support\View\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testPagesAndSlices(): void
    {
        $items = range(1, 60);
        $page = Pagination::fromQuery(['page' => '2'], count($items));

        self::assertSame(3, $page->pages());
        self::assertSame(2, $page->page);
        self::assertSame(range(26, 50), $page->slice($items));
        self::assertSame([26, 50], [$page->first(), $page->last()]);
        self::assertTrue($page->hasPrevious());
        self::assertTrue($page->hasNext());
    }

    public function testOutOfRangeAndJunkPagesAreClamped(): void
    {
        self::assertSame(3, Pagination::fromQuery(['page' => '99'], 60)->page);
        self::assertSame(1, Pagination::fromQuery(['page' => '0'], 60)->page);
        self::assertSame(1, Pagination::fromQuery(['page' => '-2'], 60)->page);
        self::assertSame(1, Pagination::fromQuery(['page' => ['x']], 60)->page);
        self::assertSame(1, Pagination::fromQuery([], 60)->page);
    }

    public function testEmptyList(): void
    {
        $page = Pagination::fromQuery([], 0);

        self::assertSame(1, $page->pages());
        self::assertSame([0, 0], [$page->first(), $page->last()]);
        self::assertFalse($page->hasNext());
        self::assertSame([], $page->slice([]));
    }
}
