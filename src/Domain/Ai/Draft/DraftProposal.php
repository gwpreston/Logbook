<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Draft;

/**
 * A draft a tool has validated and wants kept as a card (spec.md §7.26):
 * the API-shaped input, what the card shows, and the create form's values
 * for *Edit*. The tool runs inside a transaction that is rolled back, so
 * the registry stores the proposal after it.
 */
final readonly class DraftProposal
{
    /**
     * @param array<string, mixed> $input the API body, as the writer reads it
     * @param array<string, mixed> $card the formatted lines, derived marks and warnings
     * @param array<string, string> $formValues the create form's values in the user's units and language
     */
    public function __construct(
        public DraftKind $kind,
        public int $vehicleId,
        public array $input,
        public array $card,
        public array $formValues,
    ) {
    }
}
