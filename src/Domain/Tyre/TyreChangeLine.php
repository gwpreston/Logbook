<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * What one tyre change did to one tyre (spec.md §6 TyreChangeLine).
 */
final readonly class TyreChangeLine
{
    public function __construct(
        public int $tyreId,
        public TyreLineAction $action,
        /**
         * `on` / `move`: the tyre's position after the line. `off` / `retire` /
         * `repair`: where it was (for summaries; the replay never reads it).
         */
        public ?TyrePosition $position = null,
        /** The tread depth measured at this change, mm (canonical, 3 places); null when not measured. */
        public ?string $treadMm = null,
    ) {
    }

    public function withTread(?string $treadMm): self
    {
        return new self($this->tyreId, $this->action, $this->position, $treadMm);
    }
}
