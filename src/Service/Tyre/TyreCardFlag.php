<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Service\Maintenance\DueStatus;

/**
 * One status of a fitted tyre's *Current tyres* card (spec.md §7.17, Phase
 * 33.3): its words (`tyre.flag.<key>`), status tone and icon, so colour is
 * never alone. From the existing judgement and legal flags; nothing new is
 * judged here.
 */
final readonly class TyreCardFlag
{
    /** Most severe first: the first is the card's pill, the rest are listed below it. */
    private const array ORDER = [
        'legal_below',
        'wear_overdue',
        'legal_may_be_below',
        'age_overdue',
        'wear_soon',
        'age_soon',
    ];

    private function __construct(
        /** Translation key under `tyre.flag.`. */
        public string $key,
        /** Modifier of the .pill status colours. */
        public string $tone,
        public string $icon,
    ) {
    }

    /**
     * The tyre's flags, most severe first. *Good* when it is judged and
     * nothing is due; none at all when nothing about it can be judged (no
     * depth measured and no DOT date), so the card claims nothing.
     *
     * @return list<self>
     */
    public static function of(?TyreStanding $standing, TyreView $view): array
    {
        $keys = [];
        if ($view->wear->legal !== null) {
            $keys[] = 'legal_' . $view->wear->legal->value;
        }
        $standings = $standing === null ? [] : [$standing, ...$standing->others];
        foreach ($standings as $one) {
            if ($one->reason !== null && ($one->status === DueStatus::Overdue || $one->status === DueStatus::Soon)) {
                $keys[] = $one->reason . '_' . $one->status->value;
            }
        }
        if ($keys === []) {
            return $standing !== null && $standing->status === DueStatus::Ok
                ? [new self('good', DueStatus::Ok->tone(), 'check_circle')]
                : [];
        }
        $keys = array_values(array_unique($keys));
        usort($keys, static fn (string $a, string $b): int
            => array_search($a, self::ORDER, true) <=> array_search($b, self::ORDER, true));

        return array_map(static fn (string $key): self => new self(
            $key,
            str_ends_with($key, '_soon') ? DueStatus::Soon->tone() : DueStatus::Overdue->tone(),
            str_starts_with($key, 'legal_') ? 'error' : 'warning',
        ), $keys);
    }
}
