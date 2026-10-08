<?php

declare(strict_types=1);

namespace Logbook\Service\Issue;

use RuntimeException;

/**
 * No such issue (or update) on the vehicle: a 404.
 */
final class IssueNotFound extends RuntimeException
{
}
