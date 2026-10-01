<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

use DateTimeImmutable;

/**
 * An incident as entered and validated (spec.md §6 Incident, §7.29): what
 * happened, the damage, the other party and the claim. Dates are calendar
 * dates (midnight UTC; see Support\Date\LocalTime), never converted
 * through a time zone.
 */
final readonly class IncidentData
{
    /**
     * @param list<DamageArea> $damageAreas in DamageArea order
     */
    public function __construct(
        public DateTimeImmutable $occurredOn,
        public IncidentType $type,
        /** Local time of day, "HH:MM"; null when not known. */
        public ?string $occurredAtTime = null,
        public ?string $location = null,
        public Fault $fault = Fault::Unknown,
        public ?string $description = null,
        public array $damageAreas = [],
        public ?Severity $severity = null,
        /** A user who can view the vehicle; null for none or a name. */
        public ?int $driverUserId = null,
        /** Someone without an account. */
        public ?string $driverName = null,
        public ?string $otherPartyName = null,
        public ?string $otherPartyRegistration = null,
        public ?string $otherPartyInsurer = null,
        public ?string $policeReference = null,
        public IncidentStatus $status = IncidentStatus::Open,
        public ?DateTimeImmutable $closedOn = null,
        public WriteOffCategory $writeOff = WriteOffCategory::None,
        public ?string $notes = null,
        public Claim $claim = new Claim(),
    ) {
    }
}
