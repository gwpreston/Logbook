<?php

declare(strict_types=1);

namespace Logbook\Service\Navigation;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Reminder\DueCounts;

/**
 * What the signed-in shell shows besides the menu (spec.md §8): the active
 * vehicles with their status dots, and the Reminders badge.
 */
final readonly class Sidebar
{
    /**
     * @param list<Vehicle> $vehicles active vehicles, in garage order
     */
    public function __construct(
        public array $vehicles,
        public DueCounts $counts,
    ) {
    }
}
