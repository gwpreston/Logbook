<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

/**
 * How many of one vehicle's open reminders are overdue and due soon: the
 * "N due" badges and the sidebar's status dot (spec.md §7.1, §8).
 */
final readonly class VehicleDueCount
{
    public function __construct(
        public int $overdue = 0,
        public int $due = 0,
    ) {
    }

    /**
     * Overdue and due soon together.
     */
    public function total(): int
    {
        return $this->overdue + $this->due;
    }

    /**
     * Status colour: `overdue` (red) with any overdue, `soon` (amber) with
     * any due soon, else `ok` (green). Matches the .pill modifiers.
     */
    public function tone(): string
    {
        return match (true) {
            $this->overdue > 0 => 'overdue',
            $this->due > 0 => 'soon',
            default => 'ok',
        };
    }

    public function add(bool $overdue): self
    {
        return $overdue ? new self($this->overdue + 1, $this->due) : new self($this->overdue, $this->due + 1);
    }
}
