<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyreSet;

/**
 * Stored tyres of one set (or of none), for *In storage*.
 */
final readonly class TyreSetGroup
{
    /**
     * @param list<TyreView> $tyres
     */
    public function __construct(
        public ?TyreSet $set,
        public array $tyres,
    ) {
    }
}
