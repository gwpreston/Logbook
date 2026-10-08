<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

/**
 * Whether the tester read the odometer (spec.md §6 MotTest): DVSA's
 * `odometerResultType`.
 */
enum OdometerState: string
{
    case Read = 'read';
    case Unreadable = 'unreadable';
    case None = 'none';

    public static function fromDvsa(mixed $type): self
    {
        return match (is_string($type) ? strtoupper(trim($type)) : '') {
            'READ' => self::Read,
            'UNREADABLE' => self::Unreadable,
            default => self::None,
        };
    }
}
