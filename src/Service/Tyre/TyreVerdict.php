<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Service\Maintenance\DueStatus;

/**
 * A vehicle's tyres judged as one (spec.md §7.6, §7.17): the most urgent
 * status, the due point and the tyres a reminder names. Both the tyre
 * reminder and the Tyres tab badge read it, so they always agree.
 */
final readonly class TyreVerdict
{
    /**
     * @param array<int, TyreStanding> $standings by tyre id (fitted and stored tyres)
     * @param list<TyreStanding> $named the tyres the reminder names, in position order
     */
    public function __construct(
        /** Unknown when nothing is judgeable: then there is no reminder. */
        public DueStatus $status,
        public array $standings = [],
        /** WEAR or AGE: what the due point is. */
        public ?string $reason = null,
        public ?DateTimeImmutable $dueOn = null,
        public ?string $dueKm = null,
        public array $named = [],
    ) {
    }

    public function isJudgeable(): bool
    {
        return $this->status !== DueStatus::Unknown;
    }

    public function standing(int $tyreId): ?TyreStanding
    {
        return $this->standings[$tyreId] ?? null;
    }

    /**
     * Whether a named tyre is worn (the reminder then says so first).
     */
    public function isWorn(): bool
    {
        return $this->reason === TyreStanding::WEAR && $this->status === DueStatus::Overdue;
    }

    /**
     * The soonest distance left among the named tyres (wear only).
     */
    public function kmLeft(): ?string
    {
        return $this->named[0]->view->wear->kmLeft ?? null;
    }
}
