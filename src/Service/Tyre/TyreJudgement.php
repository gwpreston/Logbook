<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Service\Maintenance\DueStatus;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * Judges a vehicle's tyres against the owner's thresholds and today (spec.md
 * §7.6, §7.17). Pure: the views come in with their wear estimate and age
 * limit already worked out.
 *
 * Each fitted road tyre is judged on wear: *overdue* at or under its
 * replace-at depth (measured, or estimated now), *soon* within the lead
 * distance or lead time of its wear-out, otherwise *ok*. Each fitted or
 * stored tyre with an age limit is judged on age: *overdue* once the limit
 * has passed, *soon* within the lead time. A tyre takes the more urgent of
 * the two. The vehicle takes its most urgent tyre; its due point is the
 * soonest (dated before undated), and the tyres named are those sharing it.
 */
final class TyreJudgement
{
    /**
     * @param list<TyreView> $views every tyre of the vehicle
     * @param DateTimeImmutable $today the owner's calendar date
     * @param int $leadDays the owner's schedule lead time
     * @param string $leadKm the owner's schedule lead distance, km
     */
    public static function judge(array $views, DateTimeImmutable $today, int $leadDays, string $leadKm): TyreVerdict
    {
        $standings = [];
        $candidates = [];
        foreach ($views as $view) {
            if ($view->tyre->isRetired()) {
                continue;
            }
            $wear = self::wear($view, $today, $leadDays, $leadKm);
            $age = self::age($view, $today, $leadDays);
            $known = array_values(array_filter([$wear, $age]));
            usort($known, self::compare(...));
            $standings[$view->tyre->id] = $known[0] ?? new TyreStanding($view, DueStatus::Unknown);
            array_push($candidates, ...$known);
        }
        if ($candidates === []) {
            return new TyreVerdict(DueStatus::Unknown, $standings);
        }

        usort($candidates, self::compare(...));
        $first = $candidates[0];
        $named = array_values(array_filter(
            $candidates,
            static fn (TyreStanding $s): bool => $s->reason === $first->reason && self::sameDue($s, $first),
        ));
        $order = array_flip(array_map(static fn (TyreView $v): int => $v->tyre->id, $views));
        usort($named, static fn (TyreStanding $a, TyreStanding $b): int
            => $order[$a->view->tyre->id] <=> $order[$b->view->tyre->id]);

        return new TyreVerdict($first->status, $standings, $first->reason, $first->dueOn, $first->dueKm, $named);
    }

    private static function wear(TyreView $view, DateTimeImmutable $today, int $leadDays, string $leadKm): ?TyreStanding
    {
        $tyre = $view->tyre;
        $wear = $view->wear;
        if (!$tyre->isFitted() || $tyre->position?->isRolling() !== true || !$wear->isMeasured()) {
            return null;
        }
        if ($wear->worn) {
            // Found worn at a check: due since then; estimated worn: from the estimate.
            $measured = $wear->latest !== null && $wear->replaceAtMm !== null
                && Decimal::compare($wear->latest->treadMm, $wear->replaceAtMm) <= 0;

            return new TyreStanding(
                $view,
                DueStatus::Overdue,
                TyreStanding::WEAR,
                $measured ? $wear->latest->doneOn : $wear->wearOutOn,
                $measured ? null : $wear->wearOutKm,
            );
        }
        if (!$wear->isKnown() || $wear->kmLeft === null) {
            return null;
        }
        $soon = Decimal::compare($wear->kmLeft, $leadKm) <= 0
            || ($wear->wearOutOn !== null && LocalTime::daysBetween($today, $wear->wearOutOn) <= $leadDays);

        return new TyreStanding(
            $view,
            $soon ? DueStatus::Soon : DueStatus::Ok,
            TyreStanding::WEAR,
            $wear->wearOutOn,
            $wear->wearOutKm,
        );
    }

    private static function age(TyreView $view, DateTimeImmutable $today, int $leadDays): ?TyreStanding
    {
        if ($view->ageLimitOn === null) {
            return null;
        }
        $days = LocalTime::daysBetween($today, $view->ageLimitOn);

        return new TyreStanding(
            $view,
            match (true) {
                $days < 0 => DueStatus::Overdue,
                $days <= $leadDays => DueStatus::Soon,
                default => DueStatus::Ok,
            },
            TyreStanding::AGE,
            $view->ageLimitOn,
        );
    }

    /**
     * Most urgent first; then dated before undated, sooner first; then the
     * smaller wear-out odometer; wear before age.
     */
    private static function compare(TyreStanding $a, TyreStanding $b): int
    {
        return ($a->status->urgency() <=> $b->status->urgency())
            ?: self::compareDates($a->dueOn, $b->dueOn)
            ?: self::compareKm($a->dueKm, $b->dueKm)
            ?: (($a->reason === TyreStanding::AGE) <=> ($b->reason === TyreStanding::AGE));
    }

    private static function compareDates(?DateTimeImmutable $a, ?DateTimeImmutable $b): int
    {
        return match (true) {
            $a === null && $b === null => 0,
            $a === null => 1,
            $b === null => -1,
            default => $a <=> $b,
        };
    }

    private static function compareKm(?string $a, ?string $b): int
    {
        return match (true) {
            $a === null && $b === null => 0,
            $a === null => 1,
            $b === null => -1,
            default => Decimal::compare($a, $b),
        };
    }

    /**
     * Whether a tyre shares the due point: the same status and, below
     * overdue (where every worn or aged tyre is named), the same date and
     * wear-out odometer.
     */
    private static function sameDue(TyreStanding $a, TyreStanding $first): bool
    {
        if ($a->status !== $first->status) {
            return false;
        }

        return $first->status === DueStatus::Overdue
            || (self::compareDates($a->dueOn, $first->dueOn) === 0 && self::compareKm($a->dueKm, $first->dueKm) === 0);
    }
}
