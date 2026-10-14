<?php

declare(strict_types=1);

namespace Logbook\Support;

/**
 * One input to a figure: what it is, its value as written for the reader,
 * its date (the "as of" of Phase 48.1) and the row it came from.
 */
final readonly class WorkingInput
{
    public function __construct(
        public string $label,
        public string $value,
        public ?AsOf $asOf = null,
        public ?WorkingLink $link = null,
    ) {
    }
}
