<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use DateTimeImmutable;

/**
 * What writing a draft gave (spec.md §7.26): the entry, whether it was
 * already logged, the warnings, what its card shows, and the create
 * form's values for *Edit*.
 */
final readonly class DraftWritten
{
    /**
     * @param list<string> $warnings warning codes, as the API names them
     * @param array<string, mixed> $card
     * @param array<string, string> $formValues
     */
    public function __construct(
        public int $entryId,
        public DateTimeImmutable $updatedAt,
        public bool $duplicate,
        public array $warnings,
        public array $card,
        public array $formValues,
    ) {
    }
}
