<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\MotHistory;

use Logbook\Repository\IssueRepository;
use Logbook\Repository\MotTestRepository;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The review card's bulk actions and History read MOT data a fixed number
 * of times, not a handful per defect or per row (spec.md §7.38 *Review
 * card*, *History*): the issues already made, the look-again date, the zone
 * and the inspection documents are read once per call, each test is
 * settled once at the end, and History counts defects in the query that
 * lists the tests.
 */
final class MotReviewQueryCountTest extends MotHistoryTestCase
{
    private const int PER_TEST = 2;
    /** The most one IssueService::create costs by itself (insert, reading, attachments, webhook, read back). */
    private const int CREATE_MOST = 7;

    public function testAddAllAsIssuesReadsOncePerCallNotOncePerDefect(): void
    {
        $small = $this->addAllAsIssues(3);
        $large = $this->addAllAsIssues(15);

        // Each issue costs its own create (6 to 7 statements); the reads around it are once per call.
        // Before they were batched, 11 per defect.
        $marginal = ($large[1] - $small[1]) / ($large[0] - $small[0]);
        self::assertLessThanOrEqual(self::CREATE_MOST, $marginal, json_encode([$small, $large]) ?: '');
        self::assertLessThan(self::CREATE_MOST * $large[0] + 40, $large[1]);
    }

    public function testHistoryCountsDefectsInTheQueryThatListsTheTests(): void
    {
        $this->countQueries = true;
        $this->start();
        $this->answer = fn (): MockResponse => $this->manyTests(4);
        $golf = $this->golf();
        $this->fetch($golf);
        $tests = $this->service($this->app, MotTestRepository::class);
        self::assertNotNull($this->counter);

        $full = null;
        $withDefects = $this->counter->during(static function () use ($tests, $golf, &$full): void {
            $full = $tests->listForVehicles([$golf->id]);
        });
        $counted = null;
        $withCounts = $this->counter->during(static function () use ($tests, $golf, &$counted): void {
            $counted = $tests->summariesForVehicles([$golf->id]);
        });

        self::assertSame(2, $withDefects);
        self::assertSame(1, $withCounts, 'one query fewer');
        self::assertIsArray($full);
        self::assertIsArray($counted);
        self::assertCount(4, $counted);
        foreach ($counted as $index => $test) {
            self::assertSame([], $test->defects);
            self::assertSame(self::PER_TEST, $test->defectCount());
            self::assertSame($full[$index]->id, $test->id);
            self::assertSame(count($full[$index]->defects), $test->defectCount());
        }
        self::assertSame([], $tests->summariesForVehicles([]));
    }

    /**
     * @return array{0: int, 1: int} the issues added, and the queries *Add all as issues* sent
     */
    private function addAllAsIssues(int $tests): array
    {
        $this->countQueries = true;
        $this->start();
        $this->answer = fn (): MockResponse => $this->manyTests($tests);
        $golf = $this->golf();
        $browser = $this->fetch($golf);
        self::assertNotNull($this->counter);

        $queries = $this->counter->during(static function () use ($browser, $golf): void {
            $browser->post('/vehicles/' . $golf->id . '/mot-history/review', ['do' => 'issues']);
        });

        $issues = $this->service($this->app, IssueRepository::class)->listForVehicle($golf->id);
        self::assertCount($tests * self::PER_TEST, $issues);
        foreach ($this->service($this->app, MotTestRepository::class)->listForVehicle($golf->id) as $test) {
            self::assertNotNull($test->reviewedAt, 'each test settled');
        }

        return [count($issues), $queries];
    }

    private function manyTests(int $count): MockResponse
    {
        $tests = [];
        for ($i = 0; $i < $count; $i++) {
            $defects = [];
            for ($d = 0; $d < self::PER_TEST; $d++) {
                $defects[] = [
                    'text' => 'Defect ' . $i . '-' . $d,
                    'type' => $d === 0 ? 'MAJOR' : 'ADVISORY',
                    'dangerous' => false,
                ];
            }
            $tests[] = [
                'completedDate' => sprintf('%d-03-01T10:00:00.000Z', 2012 + $i),
                'testResult' => 'FAILED',
                'expiryDate' => null,
                'odometerValue' => (string) (10000 + $i * 5000),
                'odometerUnit' => 'MI',
                'odometerResultType' => 'READ',
                'motTestNumber' => (string) (500000000000 + $i),
                'dataSource' => 'DVSA',
                'defects' => $defects,
            ];
        }

        return new MockResponse((string) json_encode([
            'registration' => 'AB12CDE',
            'make' => 'VOLKSWAGEN',
            'model' => 'GOLF',
            'fuelType' => 'Petrol',
            'hasOutstandingRecall' => 'No',
            'motTests' => array_reverse($tests),
        ]));
    }
}
