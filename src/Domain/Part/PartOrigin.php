<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

/**
 * Where a part came from (spec.md §6 Part): it was on the car when it was
 * bought (made by *Record what came off*), or the owner fitted it.
 */
enum PartOrigin: string
{
    case Original = 'original';
    case Fitted = 'fitted';
}
