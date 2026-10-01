<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Domain\Ai\Scan\ScanTarget;

/**
 * A reading mapped onto a Logbook form (spec.md §7.27 *Mapping*, *The
 * prefilled form*): the form's values in the user's units and language,
 * which of them came from the file and the words each came from, and
 * what to check.
 */
final readonly class ScanForm
{
    /**
     * @param array<string, string> $values form field → value
     * @param array<string, string> $evidence form field → the words it came from
     * @param array<string, string> $problems form field → why it was left empty (translated)
     * @param array<string, string> $checks form field → what to check (translated)
     * @param list<string> $warnings shown above the form (translated)
     * @param array<string, string> $hints form field → a suggestion beside it (translated)
     */
    public function __construct(
        public ScanKind $kind,
        public ScanTarget $target,
        public array $values,
        public array $evidence = [],
        public array $problems = [],
        public array $checks = [],
        public array $warnings = [],
        public array $hints = [],
    ) {
    }

    /**
     * @return list<string> the fields filled from the file
     */
    public function marked(): array
    {
        return array_keys(array_filter($this->values, static fn (string $v): bool => $v !== ''));
    }
}
