<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Tyre\TyreLineAction;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreSet;
use Logbook\Support\Display\DepthText;
use Logbook\Support\I18n\JoinedMessage;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DepthUnit;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The words for a tyre change (spec.md §7.16, §7.17), built in PHP as
 * translation keys and parameters: its one-line summary ("Fitted 2 ×
 * Michelin Primacy 4 (front)", "Swapped to Winter wheels", "Rotated 4
 * tyres", "Repaired front left", "Checked tread: 5.1–6.3 mm"; the depths
 * recorded on any other change follow its summary) and the title of the
 * service record it writes ("2 × Michelin Primacy 4, front").
 */
final class TyreSummary
{
    /**
     * @param array<int, Tyre> $tyres the vehicle's tyres by id
     * @param array<int, TyreSet> $sets the vehicle's sets by id
     * @param DepthUnit $unit the owner's depth unit
     */
    public static function line(
        TyreChange $change,
        array $tyres,
        array $sets,
        DepthUnit $unit = DepthUnit::Millimetre,
    ): TranslatableMessage {
        $depths = self::depths($change, $unit);
        if ($change->kind === TyreChangeKind::Check) {
            return new TranslatableMessage('tyre.summary.check', ['depths' => $depths ?? '']);
        }
        $summary = self::message('tyre.summary.', $change, $tyres, $sets);

        return $depths === null
            ? $summary
            : new TranslatableMessage('tyre.summary.with_depths', ['summary' => $summary, 'depths' => $depths]);
    }

    /**
     * The depths recorded on a change, as a range ("5.1–6.3 mm"), or null
     * when none was.
     */
    public static function depths(TyreChange $change, DepthUnit $unit): ?DepthText
    {
        $depths = array_values(array_filter(array_map(static fn (TyreChangeLine $l): ?string => $l->treadMm, $change->lines)));
        if ($depths === []) {
            return null;
        }
        usort($depths, Decimal::compare(...));

        return new DepthText($depths[0], $depths[count($depths) - 1], $unit);
    }

    /**
     * The generated service record title.
     *
     * @param array<int, Tyre> $tyres
     * @param array<int, TyreSet> $sets
     */
    public static function title(TyreChange $change, array $tyres, array $sets): TranslatableMessage
    {
        return self::message('tyre.record_title.', $change, $tyres, $sets);
    }

    /**
     * "front" for both front wheels, "rear", "all four", one position's name,
     * or the names listed.
     *
     * @param list<TyrePosition> $positions
     */
    public static function where(array $positions): TranslatableInterface
    {
        $codes = array_values(array_unique(array_map(static fn (TyrePosition $p): string => $p->value, $positions)));
        sort($codes);

        return match ($codes) {
            ['fl', 'fr'] => new TranslatableMessage('tyre.where.front'),
            ['rl', 'rr'] => new TranslatableMessage('tyre.where.rear'),
            ['fl', 'fr', 'rl', 'rr'] => new TranslatableMessage('tyre.where.all'),
            // Positions inside a sentence are lower case: "Repaired front left".
            default => count($codes) === 1
                ? new TranslatableMessage('tyre.where.' . $codes[0])
                : new JoinedMessage(array_map(
                    static fn (TyrePosition $p): TranslatableMessage => new TranslatableMessage('tyre.where.' . $p->value),
                    self::ordered($positions),
                )),
        };
    }

    /**
     * @param array<int, Tyre> $tyres
     * @param array<int, TyreSet> $sets
     */
    private static function message(string $prefix, TyreChange $change, array $tyres, array $sets): TranslatableMessage
    {
        $on = $change->linesOf(TyreLineAction::On);
        $retired = $change->linesOf(TyreLineAction::Retire);

        return match ($change->kind) {
            TyreChangeKind::Existing => new TranslatableMessage($prefix . 'existing', [
                'count' => count($on),
                'where' => self::where(self::positions($on)),
            ]),
            TyreChangeKind::Fit => self::fit($prefix, $on, $tyres),
            TyreChangeKind::Swap => self::swap($prefix, $change, $tyres, $sets),
            TyreChangeKind::Rotate => new TranslatableMessage($prefix . 'rotate', [
                'count' => count($change->linesOf(TyreLineAction::Move)),
            ]),
            TyreChangeKind::Repair => new TranslatableMessage($prefix . 'repair', [
                'where' => self::where(self::positions($change->lines)),
            ]),
            TyreChangeKind::Remove => new TranslatableMessage(
                $prefix . (count($retired) === count($change->lines) ? 'retire' : 'remove'),
                ['count' => count($change->lines), 'where' => self::where(self::positions($change->lines))],
            ),
            TyreChangeKind::Check => new TranslatableMessage($prefix . 'check_where', [
                'where' => self::where(self::positions($change->lines)),
            ]),
        };
    }

    /**
     * @param list<TyreChangeLine> $on
     * @param array<int, Tyre> $tyres
     */
    private static function fit(string $prefix, array $on, array $tyres): TranslatableMessage
    {
        $names = array_unique(array_map(
            static fn (TyreChangeLine $l): string => ($tyres[$l->tyreId] ?? null)?->data->name() ?? '',
            $on,
        ));
        $name = count($names) === 1 ? (string) reset($names) : '';
        $params = ['count' => count($on), 'where' => self::where(self::positions($on))];

        return $name === ''
            ? new TranslatableMessage($prefix . 'fit', $params)
            : new TranslatableMessage($prefix . 'fit_named', $params + ['name' => $name]);
    }

    /**
     * @param array<int, Tyre> $tyres
     * @param array<int, TyreSet> $sets
     */
    private static function swap(string $prefix, TyreChange $change, array $tyres, array $sets): TranslatableMessage
    {
        $setIds = array_unique(array_map(
            static fn (TyreChangeLine $l): ?int => $tyres[$l->tyreId]->setId ?? null,
            $change->linesOf(TyreLineAction::On),
        ));
        $set = count($setIds) === 1 ? ($sets[(int) reset($setIds)] ?? null) : null;

        return $set === null
            ? new TranslatableMessage($prefix . 'swap', [
                // The tyres that went on (or, for a swap that only took tyres off, those).
                'count' => count($change->linesOf(TyreLineAction::On)) ?: count($change->lines),
            ])
            : new TranslatableMessage($prefix . 'swap_to', ['set' => $set->data->name]);
    }

    /**
     * @param list<TyreChangeLine> $lines
     * @return list<TyrePosition>
     */
    private static function positions(array $lines): array
    {
        return array_values(array_filter(array_map(static fn (TyreChangeLine $l): ?TyrePosition => $l->position, $lines)));
    }

    /**
     * @param list<TyrePosition> $positions
     * @return list<TyrePosition> in the order positions are shown
     */
    private static function ordered(array $positions): array
    {
        $order = array_flip(array_map(static fn (TyrePosition $p): string => $p->value, TyrePosition::cases()));
        $unique = [];
        foreach ($positions as $position) {
            $unique[$position->value] = $position;
        }
        uasort($unique, static fn (TyrePosition $a, TyrePosition $b): int => $order[$a->value] <=> $order[$b->value]);

        return array_values($unique);
    }
}
