<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartFitting;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Part\RemovalOutcome;

/**
 * A part's fittings checked and read (spec.md §7.39 *Status*, *Fittings*).
 * Pure: the fittings in, the status or a refusal out.
 */
final class PartTimeline
{
    /**
     * The status the fittings give: none, or the last one removed as *Kept*,
     * is stored; an open one is fitted; any other outcome is gone.
     *
     * @param list<PartFitting> $fittings oldest first
     */
    public static function status(array $fittings): PartStatus
    {
        $last = $fittings === [] ? null : $fittings[count($fittings) - 1];
        if ($last === null) {
            return PartStatus::Stored;
        }
        if ($last->isOpen()) {
            return PartStatus::Fitted;
        }

        return ($last->outcome ?? RemovalOutcome::Kept)->status();
    }

    /**
     * Refuse fittings that overlap, run backwards, or leave the part gone
     * before a later fitting.
     *
     * @param list<PartFitting> $fittings oldest first
     * @throws PartChangeRefused
     */
    public static function check(Part $part, array $fittings): void
    {
        usort($fittings, static fn (PartFitting $a, PartFitting $b): int
            => ($a->fittedOn <=> $b->fittedOn) ?: $a->id <=> $b->id);
        $count = count($fittings);
        foreach ($fittings as $i => $fitting) {
            if ($fitting->removedOn !== null && $fitting->removedOn < $fitting->fittedOn) {
                throw new PartChangeRefused('part.refused.removed_before_fitted', [
                    'part' => $part->label(),
                    'fitted' => $fitting->fittedOn,
                    'removed' => $fitting->removedOn,
                ]);
            }
            $next = $fittings[$i + 1] ?? null;
            if ($next === null) {
                continue;
            }
            if ($fitting->removedOn === null || $fitting->removedOn > $next->fittedOn) {
                throw new PartChangeRefused('part.refused.overlap', [
                    'part' => $part->label(),
                    'first' => $fitting->fittedOn,
                    'second' => $next->fittedOn,
                ]);
            }
            if ($i < $count - 1 && ($fitting->outcome ?? RemovalOutcome::Kept) !== RemovalOutcome::Kept) {
                throw new PartChangeRefused('part.refused.gone_before', [
                    'part' => $part->label(),
                    'removed' => $fitting->removedOn,
                ]);
            }
        }
    }

    /**
     * The open fitting, if the part is on the vehicle.
     *
     * @param list<PartFitting> $fittings
     */
    public static function open(array $fittings): ?PartFitting
    {
        foreach ($fittings as $fitting) {
            if ($fitting->isOpen()) {
                return $fitting;
            }
        }

        return null;
    }

    /**
     * The first fitting (the distance limit of a warranty counts from it).
     *
     * @param list<PartFitting> $fittings oldest first
     */
    public static function first(array $fittings): ?PartFitting
    {
        return $fittings[0] ?? null;
    }
}
