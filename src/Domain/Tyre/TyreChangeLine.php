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
        /** The tyre's position after the line (`on` and `move` only). */
        public ?TyrePosition $position = null,
    ) {
    }
}
