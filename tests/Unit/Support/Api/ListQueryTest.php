<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Api;

use DateTimeImmutable;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ListQuery;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ListQueryTest extends TestCase
{
    /**
     * @param array<string, string> $query
     */
    private static function query(array $query): ListQuery
    {
        return ListQuery::fromRequest((new ServerRequestFactory())->createServerRequest('GET', '/x')->withQueryParams($query));
    }

    /**
     * @return list<array{0: DateTimeImmutable, 1: int}>
     */
    private static function items(): array
    {
        // Two on the same instant: the id breaks the tie.
        return [
            [new DateTimeImmutable('2026-09-01T08:00:00Z'), 1],
            [new DateTimeImmutable('2026-09-10T08:00:00Z'), 3],
            [new DateTimeImmutable('2026-09-10T08:00:00Z'), 2],
            [new DateTimeImmutable('2026-09-20T23:59:59Z'), 4],
            [new DateTimeImmutable('2026-09-21T00:00:00Z'), 5],
        ];
    }

    /**
     * @param array<string, string> $query
     * @return array{items: list<int>, cursor: ?string}
     */
    private static function page(array $query): array
    {
        $page = self::query($query)->page(self::items(), static fn (array $item): array => $item);

        return ['items' => array_column($page['items'], 1), 'cursor' => $page['cursor']];
    }

    public function testPagesAreNewestFirstAndTheCursorContinues(): void
    {
        $first = self::page(['limit' => '2']);
        self::assertSame([5, 4], $first['items']);
        self::assertNotNull($first['cursor']);

        $second = self::page(['limit' => '2', 'cursor' => $first['cursor']]);
        self::assertSame([3, 2], $second['items']);
        self::assertNotNull($second['cursor']);

        $last = self::page(['limit' => '2', 'cursor' => $second['cursor']]);
        self::assertSame([1], $last['items']);
        self::assertNull($last['cursor'], 'no next page after the last');

        self::assertSame(['items' => [5, 4, 3, 2, 1], 'cursor' => null], self::page([]), 'a full page has no next');
        self::assertNull(self::page(['limit' => '5'])['cursor'], 'exactly the limit: nothing more');
    }

    public function testSinceAndUntilTakeDatesAsWholeUtcDaysOrInstants(): void
    {
        self::assertSame([4, 3, 2], self::page(['since' => '2026-09-10', 'until' => '2026-09-20'])['items']);
        self::assertSame([5, 4], self::page(['since' => '2026-09-20T23:59:59Z'])['items']);
        self::assertSame([4, 3, 2, 1], self::page(['until' => '2026-09-21T01:59:59+02:00'])['items']);
    }

    public function testBadParametersAreRefused(): void
    {
        foreach (
            [['limit' => '0'], ['limit' => '201'], ['limit' => '1.5'], ['cursor' => '!!'], ['cursor' => base64_encode('x:y')],
            ['since' => '2026-13-01'], ['until' => '2026-09-10T08:00:00'], ['since' => 'today']] as $query
        ) {
            try {
                self::query($query);
                self::fail('accepted ' . json_encode($query));
            } catch (ApiProblem $problem) {
                self::assertSame(400, $problem->status);
                self::assertSame('invalid_parameter', $problem->problemCode);
            }
        }
        self::assertSame(200, self::query(['limit' => '200'])->limit);
    }

    public function testInstantsNeedAZone(): void
    {
        self::assertSame('2026-09-29T07:42:00+00:00', ListQuery::instant('2026-09-29T08:42:00+01:00')?->format('c'));
        self::assertSame('2026-09-29T07:42:00+00:00', ListQuery::instant('2026-09-29T07:42Z')?->format('c'));
        self::assertNull(ListQuery::instant('2026-09-29T07:42:00'));
        self::assertNull(ListQuery::instant('2026-02-30T07:42:00Z'));
        self::assertNull(ListQuery::instant('2026-09-29T25:00:00Z'));
    }
}
