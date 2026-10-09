<?php

declare(strict_types=1);

namespace Logbook\Service\History;

/**
 * The print view's options (spec.md §7.16), from its plain GET form: the
 * kinds to include and whether to show costs.
 *
 * Until the form is sent (no `options=1`) the kinds are everything but fuel.
 * Costs show only with `costs=1`; anything else, absent included, hides them
 * and the purchase and sale prices, so the default is the copy that can be
 * handed to a buyer. 1.3.0 showed costs until the form was sent; such a link
 * now hides them, and a link that hid them still does: never the other way
 * round.
 */
final readonly class PrintOptions
{
    /**
     * @param list<HistoryChip> $chosen
     */
    public function __construct(
        public array $chosen,
        public bool $costs,
    ) {
    }

    /**
     * @param array<array-key, mixed> $query
     * @param list<HistoryChip> $available the chips of the modules switched on, without *Everything*
     */
    public static function fromQuery(array $query, array $available): self
    {
        $sent = ($query['options'] ?? '') === '1';
        $picked = $sent ? (is_array($query['kinds'] ?? null) ? $query['kinds'] : []) : null;
        $chosen = array_values(array_filter(
            $available,
            // Fill-ups and incidents are off until ticked (spec.md §7.16, §7.29).
            static fn (HistoryChip $chip): bool => $picked === null
                ? $chip !== HistoryChip::Fuel && $chip !== HistoryChip::Incidents
                : in_array($chip->value, $picked, true),
        ));

        return new self($chosen, ($query['costs'] ?? '') === '1');
    }

    /**
     * The feed kinds to list: milestones always, then the chosen chips'.
     * Never valuations (spec.md §7.16): a service history handed to a buyer
     * must not carry the seller's own valuations. Never trips (§7.22):
     * they are where someone went. Never an issue's *noticed* line (Phase
     * 40.1, §7.37): a fixed issue prints with its fix, an open one not at all.
     * Never an MOT test's line (Phase 41, §7.38): the sale pack summarises them.
     *
     * @return list<ActivityKind>
     */
    public function kinds(): array
    {
        $kinds = [ActivityKind::Milestone];
        foreach ($this->chosen as $chip) {
            array_push($kinds, ...$chip->kinds());
        }

        return array_values(array_filter(
            $kinds,
            static fn (ActivityKind $kind): bool => !in_array(
                $kind,
                [ActivityKind::Valuation, ActivityKind::Trip, ActivityKind::IssueNoticed, ActivityKind::MotTest],
                true,
            ),
        ));
    }
}
