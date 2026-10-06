<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

/**
 * Which row of the guard table (spec.md §7.36) this start is in.
 */
enum DemoState: string
{
    /** `DEMO_MODE` off and no marker: a normal instance. */
    case Off = 'off';
    /** `DEMO_MODE` off, marker present: demo features are inert, data untouched. */
    case Inert = 'inert';
    /** `DEMO_MODE` on, nothing in the database: the sample data is about to be seeded. */
    case NeedsSeed = 'needs_seed';
    /** `DEMO_MODE` on, marker present: the demo is running. */
    case Active = 'active';
    /** `DEMO_MODE` on, but refused: the app runs as a normal instance, nothing is deleted. */
    case Refused = 'refused';
}
