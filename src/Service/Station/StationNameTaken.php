<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Station\Station;
use RuntimeException;

/**
 * Another station already has this name (normalised): merge them instead.
 */
final class StationNameTaken extends RuntimeException
{
    public function __construct(public readonly Station $other)
    {
        parent::__construct(sprintf('Station %d already has this name.', $other->id));
    }
}
