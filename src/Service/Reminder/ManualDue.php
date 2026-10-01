<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderStatus;

/**
 * A manual reminder judged against today (ReminderRules::manual()).
 */
final readonly class ManualDue
{
    public function __construct(
        public ReminderStatus $status,
        /** The sooner of the due date and the projected date of the odometer; null when neither is known. */
        public ?DateTimeImmutable $on,
        /** $on is a projection from the vehicle's usual mileage. */
        public bool $projected,
    ) {
    }
}
