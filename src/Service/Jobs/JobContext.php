<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Closure;
use Psr\Log\LoggerInterface;

/**
 * What a running job gets: the app logger (whose lines become the run's
 * output while it runs, RunCapture) and a check to stop early (the run's
 * time is nearly up).
 */
final readonly class JobContext
{
    /**
     * @param Closure(): bool $cancelled
     */
    public function __construct(
        public LoggerInterface $logger,
        private Closure $cancelled,
    ) {
    }

    public function cancelled(): bool
    {
        return ($this->cancelled)();
    }
}
