<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use RuntimeException;

/**
 * No such reminder for this owner.
 */
final class ReminderNotFound extends RuntimeException
{
}
