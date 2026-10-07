<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A user's quiet hours (spec.md §7.11 *Quiet hours*, Phase 36.4, #250): a
 * start and an end time in their time zone. Quiet from the start up to, but
 * not including, the end, by the clock on that day; past midnight when the
 * start is later than the end (22:00 to 07:00). So on a DST change day the
 * quiet period is an hour longer or shorter.
 */
final readonly class QuietHours
{
    private const string PATTERN = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    private function __construct(
        public string $start,
        public string $end,
    ) {
    }

    /**
     * Null unless both are valid `HH:MM` times and differ.
     */
    public static function of(string $start, string $end): ?self
    {
        if (preg_match(self::PATTERN, $start) !== 1 || preg_match(self::PATTERN, $end) !== 1 || $start === $end) {
            return null;
        }

        return new self($start, $end);
    }

    public static function fromStored(mixed $value): ?self
    {
        if (!is_array($value) || !is_string($value['start'] ?? null) || !is_string($value['end'] ?? null)) {
            return null;
        }

        return self::of($value['start'], $value['end']);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toStored(): array
    {
        return ['start' => $this->start, 'end' => $this->end];
    }

    public function contains(DateTimeImmutable $now, DateTimeZone $timeZone): bool
    {
        // Wall-clock minutes of the day; "HH:MM" strings compare in the same order.
        $time = $now->setTimezone($timeZone)->format('H:i');

        return $this->start < $this->end
            ? $time >= $this->start && $time < $this->end
            : $time >= $this->start || $time < $this->end;
    }
}
