<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use Logbook\Domain\Ai\Draft\AiDraft;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * A draft after *Add*: the closed draft, its vehicle, and what was written
 * (or found already logged).
 */
final readonly class DraftApplied
{
    public function __construct(
        public AiDraft $draft,
        public Vehicle $vehicle,
        public DraftWritten $written,
    ) {
    }
}
