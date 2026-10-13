<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * How old the last backup is, in the viewer's time zone, and whether it is
 * too old (spec.md §7.13): the age in calendar days is **more than** the
 * threshold, or, with no backup, the first account is. *Never* and *I back
 * up another way* turn the warning off.
 */
final readonly class BackupAge
{
    public function __construct(
        private BackupRecorder $recorder,
        private BackupWarning $warning,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function for(User $viewer): BackupStatus
    {
        $zone = $viewer->preferences->timeZone();
        $now = $this->clock->now();
        $last = $this->recorder->last();
        $age = $last === null ? null : self::days($last->at, $now, $zone);
        $threshold = $this->warning->days();
        $elsewhere = $this->warning->elsewhere();

        $warning = false;
        if ($threshold !== null && $elsewhere === null) {
            if ($age !== null) {
                $warning = $age > $threshold;
            } else {
                // Never backed up: count from the install's first account.
                $first = $this->users->firstCreatedAt();
                $warning = $first !== null && self::days($first, $now, $zone) > $threshold;
            }
        }

        return new BackupStatus($last, $age, $threshold, $elsewhere, $warning);
    }

    /**
     * Calendar days from $from's date to $to's, both in $zone (0 for a
     * time in the future).
     */
    public static function days(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $zone): int
    {
        $start = $from->setTimezone($zone)->setTime(0, 0);
        $end = $to->setTimezone($zone)->setTime(0, 0);
        $diff = $start->diff($end);

        return $diff->invert === 1 ? 0 : (int) $diff->days;
    }
}
