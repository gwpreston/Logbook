<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * The UK insurance write-off category (spec.md §6 Incident); `none` when not written off.
 */
enum WriteOffCategory: string
{
    case None = 'none';
    case CatN = 'cat_n';
    case CatS = 'cat_s';
    case CatB = 'cat_b';
    case CatA = 'cat_a';

    public function labelKey(): string
    {
        return 'incident.write_off.' . $this->value;
    }

    public function isWrittenOff(): bool
    {
        return $this !== self::None;
    }
}
