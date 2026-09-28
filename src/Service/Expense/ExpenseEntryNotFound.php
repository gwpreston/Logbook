<?php

declare(strict_types=1);

namespace Logbook\Service\Expense;

use RuntimeException;

/**
 * No such expense on this vehicle.
 */
final class ExpenseEntryNotFound extends RuntimeException
{
}
