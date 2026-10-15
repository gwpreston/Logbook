<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

/**
 * The state a part was in when bought (spec.md §6 Part).
 */
enum PartCondition: string
{
    case New = 'new';
    case Used = 'used';
    case Reconditioned = 'reconditioned';

    public function labelKey(): string
    {
        return 'part.condition.' . $this->value;
    }
}
