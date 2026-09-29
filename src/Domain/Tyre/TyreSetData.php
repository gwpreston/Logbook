<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * A tyre set as entered (spec.md §6 TyreSet).
 */
final readonly class TyreSetData
{
    public function __construct(
        public string $name,
        public ?string $storageLocation = null,
        public ?string $notes = null,
    ) {
    }
}
