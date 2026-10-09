<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

/**
 * One defect as DVSA lists it on a test, before it is stored.
 */
final readonly class MotDefectRecord
{
    public function __construct(
        public MotDefectType $type,
        public string $text,
        public bool $dangerous,
    ) {
    }
}
