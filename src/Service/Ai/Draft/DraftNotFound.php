<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use RuntimeException;

/**
 * No draft with that id for this user: another user's draft is not found.
 */
final class DraftNotFound extends RuntimeException
{
}
