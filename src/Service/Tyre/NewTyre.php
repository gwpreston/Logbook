<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;

/**
 * A tyre a change puts on the vehicle for the first time.
 */
final readonly class NewTyre
{
    public function __construct(
        public TyrePosition $position,
        public TyreData $data,
        /** The tread depth measured as it went on, mm; null when not measured. */
        public ?string $treadMm = null,
    ) {
    }
}
