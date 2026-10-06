<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use IntlCalendar;
use IntlGregorianCalendar;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Support\Date\LocalTime;

/**
 * A month of reminders (spec.md §7.6 *Calendar view*, §7.8 *Calendar*):
 * the reminders the list shows, placed on their due dates, as weeks of
 * seven days starting on the locale's first day of the week. Built from a
 * ReminderOverview, so it never reads reminders itself.
 *
 * Due dates are calendar dates (midnight UTC) and are matched by their
 * `Y-m-d`, never shifted through a time zone.
 */
final readonly class CalendarMonth
{
    /** *Previous* and *Next* stop this many months either side of today. */
    public const int REACH_MONTHS = 60;
    /** The overdue strip links this many. */
    public const int OVERDUE_LINKS = 3;

    /**
     * @param DateTimeImmutable $month the first day of the month
     * @param list<CalendarWeek> $weeks
     * @param list<ReminderEntry> $overdue every open overdue reminder, most overdue first
     * @param list<ReminderEntry> $undated open reminders with no due date
     * @param bool $closed whether done and dismissed ones are shown
     */
    private function __construct(
        public DateTimeImmutable $month,
        public DateTimeImmutable $today,
        public array $weeks,
        public array $overdue,
        public array $undated,
        public bool $closed,
    ) {
    }

    /**
     * @param DateTimeImmutable $month any day of the month (a calendar date)
     * @param string $locale ICU locale: the first day of the week and week numbers
     */
    public static function of(ReminderOverview $overview, DateTimeImmutable $month, string $locale, bool $closed): self
    {
        $first = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), 1)->setTime(0, 0);
        $last = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), (int) $first->format('t'));

        // Gregorian whatever the locale's default calendar: due dates are Gregorian.
        $calendar = new IntlGregorianCalendar('UTC', $locale);
        $firstWeekday = self::isoWeekday($calendar->getFirstDayOfWeek());
        $lastWeekday = ($firstWeekday + 5) % 7 + 1;
        $start = $first->modify(sprintf('-%d days', ((int) $first->format('N') - $firstWeekday + 7) % 7));
        $end = $last->modify(sprintf('+%d days', ($lastWeekday - (int) $last->format('N') + 7) % 7));

        // Open ones first, in the list's order (most urgent first), then closed ones.
        $byDay = [];
        foreach ([...$overview->open, ...($closed ? $overview->closed : [])] as $entry) {
            $due = $entry->reminder->dueOn;
            if ($due !== null) {
                $byDay[$due->format('Y-m-d')][] = $entry;
            }
        }

        $today = $overview->today->format('Y-m-d');
        $weeks = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+7 days')) {
            $calendar->setTime((float) $day->getTimestamp() * 1000);
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $date = $day->modify(sprintf('+%d days', $i));
                $key = $date->format('Y-m-d');
                $inMonth = $date >= $first && $date <= $last;
                $days[] = new CalendarDay($date, $inMonth, $key === $today, $inMonth ? ($byDay[$key] ?? []) : []);
            }
            $weeks[] = new CalendarWeek($calendar->get(IntlCalendar::FIELD_WEEK_OF_YEAR), $days);
        }

        $overdue = array_values(array_filter(
            $overview->open,
            static fn (ReminderEntry $e): bool => $e->reminder->status === ReminderStatus::Overdue,
        ));
        usort($overdue, static fn (ReminderEntry $a, ReminderEntry $b): int
            => self::dueOrder($a, $b) ?: $a->reminder->id <=> $b->reminder->id);

        return new self(
            $first,
            $overview->today,
            $weeks,
            $overdue,
            array_values(array_filter($overview->open, static fn (ReminderEntry $e): bool => $e->reminder->dueOn === null)),
            $closed,
        );
    }

    /**
     * The month a `?month=YYYY-MM` names, else the month of a valid
     * `?day=`, else today's month (spec.md §7.6 *Switch*).
     */
    public static function chosen(mixed $month, mixed $day, DateTimeImmutable $today): DateTimeImmutable
    {
        if (is_string($month) && preg_match('/^(\d{4})-(\d{2})$/', $month, $m) === 1) {
            $parsed = LocalTime::parseDate($m[1] . '-' . $m[2] . '-01');
            if ($parsed !== null) {
                return $parsed;
            }
        }
        $parsed = is_string($day) ? LocalTime::parseDate($day) : null;

        return self::firstOf($parsed ?? $today);
    }

    /**
     * The day a `?day=` names, if it is a real date in this month.
     */
    public function day(mixed $value): ?CalendarDay
    {
        if (!is_string($value) || LocalTime::parseDate($value) === null) {
            return null;
        }
        foreach ($this->weeks as $week) {
            foreach ($week->days as $day) {
                if ($day->inMonth && $day->key() === $value) {
                    return $day;
                }
            }
        }

        return null;
    }

    /**
     * The seven weekdays in order (the first week's dates), for the headings.
     *
     * @return list<DateTimeImmutable>
     */
    public function weekdays(): array
    {
        return array_map(static fn (CalendarDay $d): DateTimeImmutable => $d->date, $this->weeks[0]->days);
    }

    /**
     * The days of this month (no neighbouring days).
     *
     * @return list<CalendarDay>
     */
    public function days(): array
    {
        $days = [];
        foreach ($this->weeks as $week) {
            foreach ($week->days as $day) {
                if ($day->inMonth) {
                    $days[] = $day;
                }
            }
        }

        return $days;
    }

    /** Whether any day of the month has an item. */
    public function hasItems(): bool
    {
        foreach ($this->weeks as $week) {
            if ($week->hasItems()) {
                return true;
            }
        }

        return false;
    }

    /** Open reminders due this month: the widget's "N reminders this month". */
    public function openCount(): int
    {
        return array_sum(array_map(static fn (CalendarDay $d): int => count($d->open()), $this->days()));
    }

    /** Overdue reminders due this month: the widget's "M overdue". */
    public function overdueCount(): int
    {
        return array_sum(array_map(static fn (CalendarDay $d): int => $d->count(ReminderStatus::Overdue), $this->days()));
    }

    /**
     * @return list<ReminderEntry>
     */
    public function overdueLinks(): array
    {
        return array_slice($this->overdue, 0, self::OVERDUE_LINKS);
    }

    /** `YYYY-MM`, for `?month=`. */
    public function key(): string
    {
        return $this->month->format('Y-m');
    }

    public function previous(): ?string
    {
        return $this->step(-1);
    }

    public function next(): ?string
    {
        return $this->step(1);
    }

    private function step(int $months): ?string
    {
        $target = LocalTime::addMonths($this->month, $months);
        $todayMonth = self::firstOf($this->today);
        $distance = ((int) $target->format('Y') - (int) $todayMonth->format('Y')) * 12
            + (int) $target->format('n') - (int) $todayMonth->format('n');

        return abs($distance) <= self::REACH_MONTHS ? $target->format('Y-m') : null;
    }

    private static function firstOf(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1)->setTime(0, 0);
    }

    /**
     * ICU's day of the week (1 = Sunday … 7 = Saturday) as ISO-8601's
     * (1 = Monday … 7 = Sunday).
     */
    private static function isoWeekday(int $icu): int
    {
        return $icu === IntlCalendar::DOW_SUNDAY ? 7 : $icu - 1;
    }

    private static function dueOrder(ReminderEntry $a, ReminderEntry $b): int
    {
        $x = $a->reminder->dueOn;
        $y = $b->reminder->dueOn;

        return match (true) {
            $x === null && $y === null => 0,
            $x === null => 1,
            $y === null => -1,
            default => $x <=> $y,
        };
    }
}
