<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyreSetData;

/**
 * Which set tyres taken off go into: an existing one, a new one, or none
 * (they keep whatever set they had).
 */
final readonly class SetChoice
{
    public function __construct(
        public ?int $setId = null,
        public ?TyreSetData $newSet = null,
    ) {
    }

    public function isNone(): bool
    {
        return $this->setId === null && $this->newSet === null;
    }
}
