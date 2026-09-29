<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreRetireReason;

/**
 * A tyre change form as parsed (TyreChangeForm), for TyreChangeService::record().
 * Which fields matter depends on the kind.
 */
final readonly class TyreChangeInput
{
    /**
     * @param list<NewTyre> $new existing, fit: the tyres put on
     * @param array<string, ?TyreRetireReason> $replaced fit: position → what happens to the tyre there (null = storage)
     * @param array<int, TyrePosition> $positions swap: stored tyre → position; rotate: fitted tyre → new position
     * @param array<int, ?TyreRetireReason> $removed remove: tyre → reason (null = storage); repair: tyre → null
     */
    public function __construct(
        public TyreChangeKind $kind,
        public TyreChangeData $data,
        public ?TyreCost $cost = null,
        public array $new = [],
        public array $replaced = [],
        public array $positions = [],
        public array $removed = [],
        public SetChoice $into = new SetChoice(),
    ) {
    }
}
