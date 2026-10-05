<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;
use Logbook\Service\Maintenance\DueStatus;

/**
 * Where one tyre stands against the owner's thresholds (spec.md §7.17): its
 * status and what drives it, wear or age, with the due point. Unknown when
 * nothing about it can be judged.
 */
final readonly class TyreStanding
{
    public const string WEAR = 'wear';
    public const string AGE = 'age';

    public function __construct(
        public TyreView $view,
        public DueStatus $status,
        /** WEAR or AGE; null while unknown. */
        public ?string $reason = null,
        public ?DateTimeImmutable $dueOn = null,
        /** The wear-out odometer (wear only). */
        public ?string $dueKm = null,
        /**
         * Its other known standings, less urgent (Phase 33.3: a worn tyre can
         * be over its age limit too, and the card lists both).
         *
         * @var list<TyreStanding>
         */
        public array $others = [],
    ) {
    }

    /**
     * @param list<TyreStanding> $others
     */
    public function withOthers(array $others): self
    {
        return new self($this->view, $this->status, $this->reason, $this->dueOn, $this->dueKm, $others);
    }

    public function isKnown(): bool
    {
        return $this->reason !== null;
    }
}
