<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use RuntimeException;

/**
 * The guard refused a reset (spec.md §7.36): the database is not a demo.
 */
final class DemoResetRefused extends RuntimeException
{
}
