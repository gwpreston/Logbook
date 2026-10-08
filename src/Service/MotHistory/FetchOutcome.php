<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

/**
 * What a fetch did (spec.md §7.38 *Fetching*), for the page's message.
 */
final readonly class FetchOutcome
{
    /**
     * @param string|null $knownAs DVSA's registration when the VIN's record is under another
     * @param string|null $refusedAs "Ford Fiesta" when the make disagreed: nothing was stored
     * @param string|null $modelAs DVSA's model when it reads differently (a note only)
     */
    public function __construct(
        public bool $found,
        public int $added = 0,
        public int $updated = 0,
        public int $undated = 0,
        public ?string $knownAs = null,
        public ?string $refusedAs = null,
        public ?string $modelAs = null,
    ) {
    }

    public function stored(): bool
    {
        return $this->found && $this->refusedAs === null;
    }
}
