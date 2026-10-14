<?php

declare(strict_types=1);

namespace Logbook\Service\GettingStarted;

use Logbook\Domain\GettingStarted\StartStep;

/**
 * The getting-started card on a vehicle's overview (spec.md §7.9): "3 of 5
 * done", and the steps still open, each a link back into the flow.
 */
final readonly class StartCard
{
    /**
     * @param list<StartStep> $missing the steps on offer that are not done
     */
    public function __construct(public int $done, public int $total, public array $missing)
    {
    }
}
