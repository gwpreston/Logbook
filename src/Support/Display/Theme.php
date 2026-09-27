<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

enum Theme: string
{
    /** Follow the operating system's light/dark preference. */
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
