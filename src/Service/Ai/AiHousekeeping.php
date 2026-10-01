<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Repository\AiBusyRepository;
use Logbook\Repository\AiRequestRepository;
use Psr\Clock\ClockInterface;

/**
 * The scheduled task's AI part (spec.md §7.25): usage rows older than 90
 * days are deleted, and locks left by requests that died are cleared. The
 * monthly cap needs no reset: it is summed from this month's rows.
 */
final readonly class AiHousekeeping
{
    public const int RETENTION_DAYS = 90;

    public function __construct(
        private AiRequestRepository $requests,
        private AiBusyRepository $busy,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int usage rows deleted
     */
    public function run(): int
    {
        $now = $this->clock->now();
        $this->busy->deleteExpired($now);

        return $this->requests->deleteBefore($now->modify(sprintf('-%d days', self::RETENTION_DAYS)));
    }
}
