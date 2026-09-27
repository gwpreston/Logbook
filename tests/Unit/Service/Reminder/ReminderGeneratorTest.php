<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Maintenance\DonePoint;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceSchedule;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Maintenance\NextDue;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Maintenance\DueState;
use Logbook\Service\Maintenance\ScheduleState;
use Logbook\Service\Reminder\ReminderGenerator;
use Logbook\Service\Reminder\ReminderPreferences;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * Which reminders schedules and documents raise (spec.md §7.6 Sources).
 */
final class ReminderGeneratorTest extends TestCase
{
    private const string TODAY = '2026-09-27';

    public function testSchedulesRaiseOneReminderEachWhenTheyCanBeJudged(): void
    {
        $today = self::date(self::TODAY);
        $preferences = new ReminderPreferences(scheduleDays: 21);
        $service = self::schedule(1, 'Annual service', new NextDue(self::date('2026-10-10'), '60000.000'));
        $unknown = self::schedule(2, 'Timing belt', new NextDue());

        $serviceDue = DueState::evaluate($service->nextDue, $today, '45000.000', null, 21, $preferences->scheduleKm);
        $reminders = ReminderGenerator::fromSchedules(7, [
            new ScheduleState($service, $serviceDue),
            new ScheduleState($unknown, DueState::evaluate($unknown->nextDue, $today, '45000.000', null)),
        ], $preferences);

        self::assertCount(1, $reminders, 'a schedule with nothing to measure against raises nothing');
        $reminder = $reminders[0];
        self::assertSame(ReminderSource::Schedule, $reminder->source);
        self::assertSame(7, $reminder->vehicleId);
        self::assertSame(1, $reminder->sourceId);
        self::assertSame('Annual service', $reminder->title);
        self::assertSame('service', $reminder->category);
        self::assertSame('2026-10-10', $reminder->dueOn?->format('Y-m-d'));
        self::assertSame('60000.000', $reminder->dueKm);
        self::assertSame(21, $reminder->leadTimeDays);
        self::assertSame(ReminderStatus::Due, $reminder->status, '13 days away, lead time 21');
        self::assertSame('2026-10-10|60000.000', $reminder->occurrence);
    }

    public function testTheOccurrenceIsTheStoredDuePointNotTheDriftingProjection(): void
    {
        $today = self::date(self::TODAY);
        $schedule = self::schedule(3, 'Oil change', new NextDue(null, '50000.000'));
        $preferences = new ReminderPreferences();

        $first = ReminderGenerator::fromSchedules(1, [
            new ScheduleState($schedule, DueState::evaluate($schedule->nextDue, $today, '45000.000', 50.0)),
        ], $preferences)[0];
        $later = ReminderGenerator::fromSchedules(1, [
            new ScheduleState($schedule, DueState::evaluate($schedule->nextDue, $today, '45000.000', 40.0)),
        ], $preferences)[0];

        self::assertSame('2027-01-05', $first->dueOn?->format('Y-m-d'), 'projected: 5,000 km at 50 km a day is 100 days');
        self::assertNotEquals($first->dueOn, $later->dueOn);
        self::assertSame('|50000.000', $first->occurrence);
        self::assertSame($first->occurrence, $later->occurrence, 'same occurrence however the projection moves');
    }

    public function testDocumentsRaiseRemindersForCurrentExpiriesOnly(): void
    {
        $today = self::date(self::TODAY);
        $old = self::document(1, ComplianceType::Insurance, '2025-10-10', '2026-10-09');
        $renewal = self::document(2, ComplianceType::Insurance, '2026-10-10', '2027-10-09');
        $noExpiry = self::document(3, ComplianceType::Registration, '2020-01-01', null);
        $expired = self::document(4, ComplianceType::Inspection, '2025-09-01', '2026-09-20', 'MOT');

        $states = DocumentState::evaluateAll([$old, $renewal, $noExpiry, $expired], $today);
        $reminders = ReminderGenerator::fromDocuments(5, $states, $today, new ReminderPreferences(documentDays: 45));
        $bySource = [];
        foreach ($reminders as $reminder) {
            $bySource[$reminder->sourceId] = $reminder;
        }

        // The replaced policy and the open-ended registration raise nothing.
        self::assertSame([2, 4], array_keys(self::sorted($bySource)));
        self::assertSame(ReminderStatus::Upcoming, $bySource[2]->status, 'the renewal expires next year');
        self::assertSame('2027-10-09', $bySource[2]->occurrence);
        self::assertSame('', $bySource[2]->title, 'untitled: named by its type when shown');
        self::assertSame('insurance', $bySource[2]->category);
        self::assertSame(ReminderStatus::Overdue, $bySource[4]->status);
        self::assertSame('MOT', $bySource[4]->title);
        self::assertSame(45, $bySource[4]->leadTimeDays);
    }

    public function testDocumentLeadTimeDecidesWhenItIsDue(): void
    {
        $today = self::date(self::TODAY);
        $policy = self::document(1, ComplianceType::Insurance, '2025-11-10', '2026-11-09');
        $states = DocumentState::evaluateAll([$policy], $today);

        $thirty = ReminderGenerator::fromDocuments(1, $states, $today, new ReminderPreferences(documentDays: 30))[0];
        $sixty = ReminderGenerator::fromDocuments(1, $states, $today, new ReminderPreferences(documentDays: 60))[0];

        self::assertSame(ReminderStatus::Upcoming, $thirty->status, '43 days away');
        self::assertSame(ReminderStatus::Due, $sixty->status);
    }

    /**
     * @template T
     * @param array<int, T> $items
     * @return array<int, T>
     */
    private static function sorted(array $items): array
    {
        ksort($items);

        return $items;
    }

    private static function schedule(int $id, string $title, NextDue $next): MaintenanceSchedule
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new MaintenanceSchedule(
            $id,
            1,
            new MaintenanceScheduleData(MaintenanceCategory::Service, $title, '10000.000', 12),
            new DonePoint(),
            $next,
            $now,
            $now,
        );
    }

    private static function document(
        int $id,
        ComplianceType $type,
        ?string $start,
        ?string $expiry,
        ?string $title = null,
    ): ComplianceDocument {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new ComplianceDocument(
            $id,
            1,
            new ComplianceDocumentData(
                type: $type,
                title: $title,
                startOn: $start === null ? null : self::date($start),
                expiryOn: $expiry === null ? null : self::date($expiry),
            ),
            $now,
            $now,
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
