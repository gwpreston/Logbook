<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

/**
 * What Settings shows about an engine (spec.md §7.27): whether it answers,
 * its version and the languages it has.
 */
final readonly class OcrEngineStatus
{
    /**
     * @param list<string> $languages
     */
    public function __construct(
        public string $engine,
        public bool $available,
        public string $version = '',
        public array $languages = [],
        /** Where it runs: a binary's path or the service's address. */
        public string $location = '',
    ) {
    }
}
