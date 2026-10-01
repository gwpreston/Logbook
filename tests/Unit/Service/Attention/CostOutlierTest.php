<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Service\Attention\CostFinding;
use Logbook\Service\Attention\CostOutlier;
use Logbook\Service\Attention\Fingerprint;
use PHPUnit\Framework\TestCase;

/**
 * Maintenance cost outliers (spec.md §7.24 item 9): more than 3× the
 * category's earlier median and at least 100 above it, raised for the last
 * 12 months only. "Today" is 1 Oct 2026.
 */
final class CostOutlierTest extends TestCase
{
    private int $nextId = 1;

    public function testAnExtraDigitIsFlagged(): void
    {
        $records = [...$this->services(['200', '212', '230']), $big = $this->record('2026-03-14', '2120')];

        $findings = self::judge($records);
        self::assertCount(1, $findings);
        self::assertSame($big->id, $findings[0]->entry->id);
        self::assertSame('212.0000000', $findings[0]->median);
        self::assertTrue($findings[0]->digitSlip, '10×');
    }

    public function testThreeTimesAndAtLeast100AboveIsFlaggedButNotUnder(): void
    {
        $base = $this->services(['200', '200', '200']);
        self::assertCount(1, self::judge([...$base, $this->record('2026-03-14', '601')]), 'more than 3× and 401 above');
        self::assertCount(0, self::judge([...$base, $this->record('2026-03-14', '600')]), 'exactly 3× is not more than 3×');
    }

    public function testACheapCategoryIsNeverFlaggedUnderTheFloor(): void
    {
        $wipers = [
            $this->record('2025-01-10', '9', MaintenanceCategory::Other),
            $this->record('2025-04-10', '9', MaintenanceCategory::Other),
            $this->record('2025-07-10', '9', MaintenanceCategory::Other),
            $job = $this->record('2026-03-14', '30', MaintenanceCategory::Other),
        ];

        self::assertSame([], self::judge($wipers), '£30 is 3.3× but only £21 above');
        self::assertCount(1, self::judge($wipers, floor: 0), 'the owner set the floor to 0');
        self::assertSame($job->id, self::judge($wipers, floor: 20)[0]->entry->id);
        self::assertSame([], self::judge($wipers, multiple: 4, floor: 0), 'and the multiple is the owner’s too');
    }

    public function testFewerThanThreeEarlierRecordsIsSkipped(): void
    {
        self::assertSame([], self::judge([...$this->services(['200', '200']), $this->record('2026-03-14', '6400')]));
    }

    public function testOnlyTheSameCategoryAndCostsAbove0Count(): void
    {
        $records = [
            ...$this->services(['200', '200']),
            $this->record('2025-06-01', '0'),
            $this->record('2025-06-02', '5000', MaintenanceCategory::Repair),
            $this->record('2026-03-14', '6400'),
        ];

        self::assertSame([], self::judge($records), 'two services above 0: not enough');
    }

    public function testOnlyTheLast12MonthsAreRaised(): void
    {
        $records = [
            ...$this->services(['200', '200', '200']),
            $old = $this->record('2025-09-30', '2000'),
            $this->record('2025-10-01', '2000'),
        ];

        $findings = self::judge($records);
        self::assertCount(1, $findings);
        self::assertNotSame($old->id, $findings[0]->entry->id, 'a day over 12 months ago is history');
        self::assertSame('2025-10-01', $findings[0]->entry->data->performedOn->format('Y-m-d'));
    }

    public function testEarlierMeansByDateNotTheOrderAdded(): void
    {
        // The big job was typed first, the history after it.
        $big = $this->record('2026-03-14', '6400');
        $records = [$big, ...$this->services(['200', '212', '230'])];

        self::assertSame($big->id, self::judge($records)[0]->entry->id);
    }

    public function testTheFingerprintIsTheCostAndCategory(): void
    {
        $record = $this->record('2026-03-14', '6400');
        $same = Fingerprint::cost($record);

        self::assertSame($same, Fingerprint::cost($record));
        self::assertNotSame($same, Fingerprint::cost($this->record('2026-03-14', '640', id: $record->id)));
        self::assertNotSame($same, Fingerprint::cost($this->record('2026-03-14', '6400', MaintenanceCategory::Repair, $record->id)));
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * @param list<MaintenanceEntry> $records
     * @return list<CostFinding>
     */
    private static function judge(array $records, int $multiple = 3, int $floor = 100): array
    {
        return CostOutlier::of($records, new DateTimeImmutable('2025-10-01', new DateTimeZone('UTC')), $multiple, $floor);
    }

    /**
     * @param list<string> $costs
     * @return list<MaintenanceEntry>
     */
    private function services(array $costs): array
    {
        $records = [];
        foreach ($costs as $i => $cost) {
            $records[] = $this->record(sprintf('2024-%02d-15', $i + 1), $cost);
        }

        return $records;
    }

    private function record(
        string $date,
        string $cost,
        MaintenanceCategory $category = MaintenanceCategory::Service,
        ?int $id = null,
    ): MaintenanceEntry {
        $on = new DateTimeImmutable($date, new DateTimeZone('UTC'));

        return new MaintenanceEntry(
            $id ?? $this->nextId++,
            1,
            new MaintenanceEntryData($on, $category, 'Work', $cost),
            $on,
            $on,
        );
    }
}
