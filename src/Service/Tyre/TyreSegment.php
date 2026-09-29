<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

/**
 * A stretch a tyre rolled (spec.md §7.17): from the change that put it on a
 * non-spare position to the one that took it off, moved it to the spare or
 * retired it. An open segment ($endKm null, $open) runs to the vehicle's
 * current reading. Odometers are canonical km.
 */
final readonly class TyreSegment
{
    public function __construct(
        public ?string $startKm,
        public ?string $endKm = null,
        public bool $open = true,
    ) {
    }

    public function closedAt(?string $km): self
    {
        return new self($this->startKm, $km, false);
    }
}
