<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Ask;

use Logbook\Domain\Ai\Draft\DraftProposal;

/**
 * What a tool returned (spec.md §7.26): the data for the model (raw
 * values as decimal strings in canonical units, beside display strings in
 * the user's units, locale and currency), and for the *Sources* list a
 * line in words, its key figures and a link to the page showing the same.
 */
final readonly class ToolResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $figures display strings, e.g. "£1,284.50"
     */
    public function __construct(
        public array $data,
        public string $source,
        public array $figures = [],
        /** App-relative path with query ("/reports?range=custom&…"), without the base path. */
        public ?string $link = null,
        /** @var list<int> the vehicles the figures are about */
        public array $vehicleIds = [],
        /** A draft tool's validated entry, kept as a card once the tool's transaction is rolled back (Phase 26.3). */
        public ?DraftProposal $draft = null,
    ) {
    }
}
