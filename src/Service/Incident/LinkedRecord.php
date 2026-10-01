<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Incident\LinkKind;
use Logbook\Support\Money\Money;

/**
 * A repair, expense or tyre change as an incident page lists it (spec.md
 * §7.29 *Linked records*), and as the *Link a record* picker offers it.
 */
final readonly class LinkedRecord
{
    /**
     * @param array<string, string> $routeParams
     */
    public function __construct(
        public LinkKind $kind,
        public int $id,
        public DateTimeImmutable $date,
        /** The record's own words, or a translation key when $titleIsKey. */
        public string $title,
        public bool $titleIsKey,
        public ?string $vendor,
        /** Its own cost; null for a tyre change (its cost is its service record's). */
        public ?Money $cost,
        public string $route,
        public array $routeParams,
        public ?int $createdBy,
        /** A tyre change that follows its service record's incident (#103). */
        public bool $followsRecord = false,
        public ?int $incidentId = null,
    ) {
    }

    /**
     * The picker's value: "maintenance:12".
     */
    public function key(): string
    {
        return $this->kind->value . ':' . $this->id;
    }
}
