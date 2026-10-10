<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Digest;

use Logbook\Domain\Vehicle\Vehicle;

/**
 * A vehicle's open issues, for the digest's *Needs attention* (spec.md
 * §7.11 *The monthly briefing*, §7.37): "Golf: 2 open issues".
 */
final readonly class OpenIssues
{
    public function __construct(public Vehicle $vehicle, public int $count)
    {
    }
}
