<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Cache;

use Logbook\Support\Cache\RequestReads;
use PHPUnit\Framework\TestCase;

final class RequestReadsTest extends TestCase
{
    /** @var list<list<int>> */
    private array $calls = [];

    /**
     * A loader that finds a row for every id but 3.
     *
     * @param list<int> $ids
     * @return array<int, mixed>
     */
    public function loadAllBut3(array $ids): array
    {
        $this->calls[] = $ids;
        $found = [];
        foreach ($ids as $id) {
            if ($id !== 3) {
                $found[$id] = ['row'];
            }
        }

        return $found;
    }

    public function testOutsideARequestEveryReadLoads(): void
    {
        $reads = new RequestReads();
        $loads = 0;

        $reads->remember('t', 1, static function () use (&$loads): int {
            return ++$loads;
        });
        $second = $reads->remember('t', 1, static function () use (&$loads): int {
            return ++$loads;
        });

        self::assertSame(2, $second);
        self::assertFalse($reads->isActive());
    }

    public function testARequestLoadsAKeyOnceAndRemembersNull(): void
    {
        $reads = new RequestReads();
        $reads->begin();
        $loads = 0;
        $load = static function () use (&$loads): mixed {
            $loads++;

            return null;
        };

        $first = $reads->remember('t', 'k', $load);
        $second = $reads->remember('t', 'k', $load);

        self::assertSame([null, null], [$first, $second]);
        self::assertSame(1, $loads, 'a missing row is remembered as missing');
    }

    public function testEndingARequestForgetsEverything(): void
    {
        $reads = new RequestReads();
        $reads->begin();
        $reads->remember('t', 1, static fn (): string => 'old');
        $reads->end();
        $reads->begin();

        self::assertSame('new', $reads->remember('t', 1, static fn (): string => 'new'));
    }

    public function testPrimeLoadsOnlyWhatIsMissingAndFillsGapsWithTheEmptyValue(): void
    {
        $reads = new RequestReads();
        $reads->begin();
        $this->calls = [];

        $reads->prime('t', [1, 2, 3, 2], $this->loadAllBut3(...), []);
        $reads->prime('t', [1, 2, 3, 4], $this->loadAllBut3(...), []);

        self::assertSame([[1, 2, 3], [4]], $this->calls);
        self::assertSame(['row'], $reads->remember('t', 1, static fn (): array => ['direct']));
        self::assertSame([], $reads->remember('t', 3, static fn (): array => ['direct']), 'none found: the empty value');
    }

    public function testPrimeDoesNothingOutsideARequest(): void
    {
        $reads = new RequestReads();
        $called = false;

        $reads->prime('t', [1], static function () use (&$called): array {
            $called = true;

            return [];
        }, []);

        self::assertFalse($called);
    }

    public function testForgetDropsTheGroupsReadFromAWrittenTable(): void
    {
        $reads = new RequestReads();
        $reads->begin();
        $reads->remember('fuel_entries+stations', 1, static fn (): string => 'a');
        $reads->remember('odometer_readings', 1, static fn (): string => 'b');

        $reads->forget('stations');

        self::assertSame('a2', $reads->remember('fuel_entries+stations', 1, static fn (): string => 'a2'));
        self::assertSame('b', $reads->remember('odometer_readings', 1, static fn (): string => 'b2'));

        $reads->forgetAll();
        self::assertSame('b3', $reads->remember('odometer_readings', 1, static fn (): string => 'b3'));
    }

    public function testSwitchedOffItNeverRemembers(): void
    {
        $reads = new RequestReads();
        $reads->switchOff();
        $reads->begin();

        self::assertFalse($reads->isActive());
    }

    public function testGroupByKeepsTheOrderWithinEachId(): void
    {
        $items = [['v' => 2, 'n' => 'a'], ['v' => 1, 'n' => 'b'], ['v' => 2, 'n' => 'c']];

        $grouped = RequestReads::groupBy($items, static fn (array $item): int => $item['v']);

        self::assertSame([2, 1], array_keys($grouped));
        self::assertSame(['a', 'c'], array_column($grouped[2], 'n'));
    }
}
