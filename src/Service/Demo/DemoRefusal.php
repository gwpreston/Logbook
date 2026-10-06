<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

/**
 * Why `DEMO_MODE` was refused (spec.md §7.36).
 */
enum DemoRefusal: string
{
    /** Users exist and nothing marks the database as a demo. */
    case RealData = 'real_data';
    /** `DEMO_PASSWORD` is missing, or not 8 to 1024 characters. */
    case Password = 'password';
}
