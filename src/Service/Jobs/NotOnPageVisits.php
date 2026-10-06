<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

/**
 * A job a visitor's request must never wait for (spec.md §7.30): it is
 * left out of a pass started by a page visit, and runs from cron, the
 * Docker scheduler, the external URL or by hand.
 */
interface NotOnPageVisits extends Job
{
}
