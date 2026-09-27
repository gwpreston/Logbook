<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use RuntimeException;

/**
 * First-run setup was attempted although an account already exists.
 */
final class SetupAlreadyCompleted extends RuntimeException
{
}
