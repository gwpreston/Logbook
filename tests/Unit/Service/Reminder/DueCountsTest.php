<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Reminder\DueCounts;
use Logbook\Service\Reminder\OpenReminderRow;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * The sidebar badge, status dots and "N due" badges (spec.md §8).
 */
final class DueCountsTest extends TestCase
{
    private const array ALL_ON = [
        'fuel' => true, 'maintenance' => true, 'compliance' => true, 'reminders' => true, 'reports' => true,
    ];

    public function testCountsOverdueAndDueSoonPerVehicle(): void
    {
        $counts = DueCounts::fromRows([
            self::row(1, ReminderStatus::Overdue, '2026-09-01'),
            self::row(1, ReminderStatus::Due, '2026-09-30'),
            self::row(2, ReminderStatus::Due, '2026-10-01'),
            self::row(3, ReminderStatus::Upcoming, '2026-12-01'),
        ], self::day('2026-09-27'), self::ALL_ON);

        self::assertSame(3, $counts->total());
        self::assertSame('overdue', $counts->forVehicle(1)->tone());
        self::assertSame(2, $counts->forVehicle(1)->total());
        self::assertSame('soon', $counts->forVehicle(2)->tone());
        self::assertSame('ok', $counts->forVehicle(3)->tone());
        self::assertSame(0, $counts->forVehicle(99)->total());
    }

    public function testTheDateCanOnlyMakeAStoredStatusMoreUrgent(): void
    {
        $today = self::day('2026-09-27');
        $effective = static fn (ReminderStatus $stored, ?string $due): ReminderStatus
            => DueCounts::effectiveStatus(self::row(1, $stored, $due), $today);

        // Stored before the sync noticed the date passed.
        self::assertSame(ReminderStatus::Overdue, $effective(ReminderStatus::Due, '2026-09-20'));
        self::assertSame(ReminderStatus::Due, $effective(ReminderStatus::Upcoming, '2026-09-30'));
        // A schedule overdue by distance before its date stays overdue.
        self::assertSame(ReminderStatus::Overdue, $effective(ReminderStatus::Overdue, '2027-01-01'));
        // Distance-only: the stored status.
        self::assertSame(ReminderStatus::Due, $effective(ReminderStatus::Due, null));
    }

    public function testSwitchedOffModulesCountForNothing(): void
    {
        $rows = [
            self::row(1, ReminderStatus::Overdue, '2026-09-01', ReminderSource::Schedule),
            self::row(1, ReminderStatus::Overdue, '2026-09-01', ReminderSource::Compliance),
            self::row(1, ReminderStatus::Overdue, '2026-09-01', ReminderSource::Manual),
        ];

        $counts = DueCounts::fromRows($rows, self::day('2026-09-27'), ['maintenance' => false] + self::ALL_ON);

        self::assertSame(2, $counts->total());
        self::assertFalse(DueCounts::disabled()->enabled);
    }

    private static function row(
        int $vehicle,
        ReminderStatus $status,
        ?string $due,
        ReminderSource $source = ReminderSource::Manual,
    ): OpenReminderRow {
        return new OpenReminderRow($vehicle, $source, $status, $due === null ? null : self::day($due), 7);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
