<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\NextDue;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * A schedule's next-due point judged against today and the current odometer:
 * "whichever comes first".
 *
 * The distance limit is placed on the calendar by projecting the vehicle's
 * average daily distance; when both limits are set, the one reached sooner
 * applies (its date is dueOn). Without enough mileage history to project,
 * the date limit is shown, but the distance limit still counts towards
 * "soon" and "overdue".
 */
final readonly class DueState
{
    /** Due within this many days counts as "soon" (reminder lead times arrive in Phase 4). */
    public const int SOON_DAYS = 30;
    /** Due within this many kilometres counts as "soon". */
    public const string SOON_KM = '1000';

    public function __construct(
        public DueStatus $status,
        /** The limit that applies (the sooner one), if either can be judged. */
        public ?DueTrigger $trigger = null,
        /** When it is due: the date limit, or the projected day the distance limit is reached. */
        public ?DateTimeImmutable $dueOn = null,
        /** dueOn is an estimate from average mileage. */
        public bool $projected = false,
        /** Days from today to dueOn; negative when overdue. */
        public ?int $daysLeft = null,
        /** Kilometres to the distance limit; negative when overdue. */
        public ?string $kmLeft = null,
    ) {
    }

    /**
     * @param DateTimeImmutable $today calendar date (see LocalTime::today())
     * @param string|null $currentKm latest odometer reading, km
     * @param float|null $kmPerDay average daily distance, for the projection
     */
    public static function evaluate(NextDue $next, DateTimeImmutable $today, ?string $currentKm, ?float $kmPerDay): self
    {
        if (!$next->isKnown()) {
            return new self(DueStatus::Unknown);
        }

        $kmLeft = $next->km !== null && $currentKm !== null ? Decimal::subtract($next->km, $currentKm) : null;
        $projectedOn = null;
        if ($kmLeft !== null && $kmPerDay !== null && $kmPerDay > 0) {
            $days = Decimal::compare($kmLeft, '0') <= 0 ? 0 : (int) ceil((float) $kmLeft / $kmPerDay);
            $projectedOn = $today->modify(sprintf('+%d days', $days));
        }

        $dateOverdue = $next->on !== null && $next->on < $today;
        $kmOverdue = $kmLeft !== null && Decimal::compare($kmLeft, '0') < 0;

        $trigger = match (true) {
            $dateOverdue => DueTrigger::Date,
            $kmOverdue => DueTrigger::Distance,
            $next->on !== null && $projectedOn !== null => $projectedOn < $next->on ? DueTrigger::Distance : DueTrigger::Date,
            $next->on !== null => DueTrigger::Date,
            default => $kmLeft !== null ? DueTrigger::Distance : null,
        };
        $dueOn = $trigger === DueTrigger::Date ? $next->on : ($trigger === DueTrigger::Distance ? $projectedOn : null);
        $daysLeft = $dueOn === null ? null : LocalTime::daysBetween($today, $dueOn);

        $status = match (true) {
            $dateOverdue || $kmOverdue => DueStatus::Overdue,
            ($daysLeft !== null && $daysLeft <= self::SOON_DAYS)
                || ($kmLeft !== null && Decimal::compare($kmLeft, self::SOON_KM) <= 0) => DueStatus::Soon,
            $trigger === null => DueStatus::Unknown,
            default => DueStatus::Ok,
        };

        $projected = $trigger === DueTrigger::Distance && $dueOn !== null;

        return new self($status, $trigger, $dueOn, $projected, $daysLeft, $kmLeft);
    }
}
