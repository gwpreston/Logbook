<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;

/**
 * The claims history for one user and filter (spec.md §7.29).
 */
final readonly class ClaimsHistoryReport
{
    /**
     * @param list<ClaimsRow> $rows newest first
     * @param array<string, string> $drivers filter value => name, every driver of the user's incidents
     */
    public function __construct(
        public ClaimsFilter $filter,
        public ?DateTimeImmutable $from,
        public DateTimeImmutable $until,
        public array $rows,
        public array $drivers,
    ) {
    }
}
