<?php

declare(strict_types=1);

namespace Logbook\Domain\MotHistory;

/**
 * Who recorded a test (spec.md §6 MotTest): DVSA in Great Britain, DVA in
 * Northern Ireland, or DVSA's Commercial Vehicle Service.
 */
enum MotDataSource: string
{
    case Dvsa = 'dvsa';
    case DvaNi = 'dva_ni';
    case Cvs = 'cvs';

    public static function fromDvsa(mixed $value): self
    {
        return match (is_string($value) ? strtoupper(trim($value)) : '') {
            'DVA NI' => self::DvaNi,
            'CVS' => self::Cvs,
            default => self::Dvsa,
        };
    }
}
