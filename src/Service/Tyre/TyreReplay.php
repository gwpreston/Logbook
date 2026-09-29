<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreLineAction;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreStatus;

/**
 * Replays a vehicle's tyre changes in order (spec.md §7.17) into each tyre's
 * state and rolling segments. Pure: no database, so every sequence rule is
 * unit-tested here.
 *
 * Order is by date, then odometer, then id (TyreChange::compare, in PHP:
 * engines disagree on where a repair's null odometer sorts). Within one
 * change every line first leaves its position, then takes its new one, so
 * a rotation is checked as a whole. The rules:
 * - `on`: a new or stored tyre; its position must be free.
 * - `off`: a fitted tyre, into storage.
 * - `retire`: a fitted or stored tyre.
 * - `move`: a fitted tyre, to a free position.
 * - `repair`: a fitted tyre; nothing moves.
 * A retired tyre is never touched again. The first failure is returned.
 *
 * A segment opens when a tyre goes on (or moves) to a rolling position and
 * closes when it comes off, moves to the spare or is retired, at the
 * change's odometer.
 */
final class TyreReplay
{
    /**
     * @param list<TyreChange> $changes the vehicle's changes, in any order
     */
    public static function run(array $changes): TyreReplayResult|TyreSequenceError
    {
        usort($changes, TyreChange::compare(...));

        /** @var array<int, TyreState> $states */
        $states = [];
        /** @var array<string, int> $occupied position code → tyre id */
        $occupied = [];
        /** @var array<int, list<TyreSegment>> $segments */
        $segments = [];
        /** @var array<int, int> $fittedBy */
        $fittedBy = [];

        foreach ($changes as $change) {
            $km = $change->data->odometerKm;

            foreach ($change->lines as $line) {
                $problem = self::check($line->action, $states[$line->tyreId] ?? null);
                if ($problem !== null) {
                    return new TyreSequenceError($problem, $change->id, $line->tyreId, $line->position);
                }
            }

            // Leave: every line that moves a fitted tyre frees its position first.
            foreach ($change->lines as $line) {
                $state = $states[$line->tyreId] ?? null;
                if ($state?->position !== null && $line->action !== TyreLineAction::Repair) {
                    unset($occupied[$state->position->value]);
                }
            }

            // Take: then every tyre that goes on or moves takes its new position.
            foreach ($change->lines as $line) {
                if (!$line->action->takesPosition() || $line->position === null) {
                    continue;
                }
                if (isset($occupied[$line->position->value])) {
                    return new TyreSequenceError(TyreSequenceProblem::Occupied, $change->id, $line->tyreId, $line->position);
                }
                $occupied[$line->position->value] = $line->tyreId;
            }

            foreach ($change->lines as $line) {
                $tyre = $line->tyreId;
                $before = $states[$tyre] ?? null;
                $wasRolling = $before?->position?->isRolling() ?? false;

                $after = match ($line->action) {
                    TyreLineAction::On, TyreLineAction::Move => new TyreState(
                        TyreStatus::Fitted,
                        $line->position,
                        $line->position,
                    ),
                    TyreLineAction::Off => new TyreState(TyreStatus::Stored, null, $before?->position),
                    TyreLineAction::Retire => new TyreState(
                        TyreStatus::Retired,
                        null,
                        $before->position ?? $before->lastPosition ?? null,
                    ),
                    TyreLineAction::Repair => $before,
                };
                assert($after instanceof TyreState);
                $states[$tyre] = $after;
                if ($line->action === TyreLineAction::On) {
                    $fittedBy[$tyre] ??= $change->id;
                }

                $isRolling = $after->position?->isRolling() ?? false;
                if ($wasRolling && !$isRolling) {
                    $segments[$tyre] = self::close($segments[$tyre] ?? [], $km);
                } elseif (!$wasRolling && $isRolling) {
                    $segments[$tyre] ??= [];
                    $segments[$tyre][] = new TyreSegment($km);
                }
            }
        }

        return new TyreReplayResult($states, $segments, $fittedBy);
    }

    private static function check(TyreLineAction $action, ?TyreState $state): ?TyreSequenceProblem
    {
        if ($state?->status === TyreStatus::Retired) {
            return TyreSequenceProblem::Retired;
        }
        $fitted = $state?->status === TyreStatus::Fitted;

        return match ($action) {
            TyreLineAction::On => $fitted ? TyreSequenceProblem::AlreadyFitted : null,
            TyreLineAction::Off, TyreLineAction::Move, TyreLineAction::Repair => $fitted
                ? null
                : TyreSequenceProblem::NotFitted,
            TyreLineAction::Retire => $state === null ? TyreSequenceProblem::NotFitted : null,
        };
    }

    /**
     * @param list<TyreSegment> $segments
     * @return list<TyreSegment>
     */
    private static function close(array $segments, ?string $km): array
    {
        $last = array_key_last($segments);
        if ($last !== null && $segments[$last]->open) {
            $segments[$last] = $segments[$last]->closedAt($km);
        }

        return $segments;
    }

    /**
     * Positions of the fitted tyres (for forms and the vehicle type check).
     *
     * @param array<int, TyreState> $states
     * @return array<string, int> position code → tyre id
     */
    public static function occupied(array $states): array
    {
        $occupied = [];
        foreach ($states as $tyre => $state) {
            if ($state->status === TyreStatus::Fitted && $state->position instanceof TyrePosition) {
                $occupied[$state->position->value] = $tyre;
            }
        }

        return $occupied;
    }
}
