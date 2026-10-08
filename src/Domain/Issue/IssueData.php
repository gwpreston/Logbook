<?php

declare(strict_types=1);

namespace Logbook\Domain\Issue;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\MaintenanceCategory;

/**
 * An issue as the owner entered it, validated and in storage units
 * (spec.md §6 Issue). Decimals are canonical strings.
 */
final readonly class IssueData
{
    public function __construct(
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $noticedOn,
        public string $title,
        public IssueStatus $status = IssueStatus::Open,
        /** Kilometres, when the odometer was noted. */
        public ?string $odometerKm = null,
        public ?string $description = null,
        /** So a fix can be prefilled. */
        public ?MaintenanceCategory $category = null,
        /** The owner's judgement, never Logbook's (#310). */
        public bool $affectsSafety = false,
        /** Calendar date; only while watching. */
        public ?DateTimeImmutable $lookAgainOn = null,
        /** Kilometres; only while watching. */
        public ?string $lookAgainKm = null,
    ) {
    }

    /**
     * The same issue in another status: a look-again point is only kept
     * while watching.
     */
    public function withStatus(IssueStatus $status): self
    {
        $watching = $status === IssueStatus::Watching;

        return new self(
            noticedOn: $this->noticedOn,
            title: $this->title,
            status: $status,
            odometerKm: $this->odometerKm,
            description: $this->description,
            category: $this->category,
            affectsSafety: $this->affectsSafety,
            lookAgainOn: $watching ? $this->lookAgainOn : null,
            lookAgainKm: $watching ? $this->lookAgainKm : null,
        );
    }

    /**
     * The same issue watched with a new look-again point (either may be null).
     */
    public function watching(?DateTimeImmutable $on, ?string $km): self
    {
        return new self(
            noticedOn: $this->noticedOn,
            title: $this->title,
            status: IssueStatus::Watching,
            odometerKm: $this->odometerKm,
            description: $this->description,
            category: $this->category,
            affectsSafety: $this->affectsSafety,
            lookAgainOn: $on,
            lookAgainKm: $km,
        );
    }

    public function hasLookAgain(): bool
    {
        return $this->lookAgainOn !== null || $this->lookAgainKm !== null;
    }
}
