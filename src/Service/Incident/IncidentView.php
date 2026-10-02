<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Incident\NcdEffect;
use Logbook\Domain\Incident\Severity;
use Logbook\Domain\Incident\WriteOffCategory;

/**
 * An incident as one user may see it (spec.md §7.29 *Access*). Everyone who
 * can view the vehicle sees the summary: the date, type, damage, status,
 * photos and linked records. The rest is null unless `details`; amounts
 * also need `amounts`. Pages, exports, the API and the tools read this,
 * never the incident itself, so a hidden field cannot leak through one of
 * them.
 */
final readonly class IncidentView
{
    /**
     * @param list<DamageArea> $damageAreas
     */
    private function __construct(
        public int $id,
        public int $vehicleId,
        public ?int $createdBy,
        public DateTimeImmutable $occurredOn,
        public IncidentType $type,
        public array $damageAreas,
        public ?Severity $severity,
        public WriteOffCategory $writeOff,
        public IncidentStatus $status,
        public ?DateTimeImmutable $closedOn,
        /** The detail fields below are shown. */
        public bool $details,
        /** Amounts are shown (with `details` for the claim's). */
        public bool $amounts,
        public ?string $occurredAtTime = null,
        public ?string $location = null,
        public ?Fault $fault = null,
        public ?string $description = null,
        public ?int $driverUserId = null,
        public ?string $driverName = null,
        public ?string $otherPartyName = null,
        public ?string $otherPartyRegistration = null,
        public ?string $otherPartyInsurer = null,
        public ?string $policeReference = null,
        public ?string $notes = null,
        public ?ClaimStatus $claimStatus = null,
        public ?string $insurer = null,
        public ?int $insuranceDocumentId = null,
        public ?string $claimNumber = null,
        public ?NcdEffect $ncdAffected = null,
        public ?DateTimeImmutable $claimUpdatedOn = null,
        public ?string $excess = null,
        public ?string $payout = null,
        /** What a repair may cost (Phase 27.2): information, never in the costs. */
        public ?string $repairEstimate = null,
        /** Linked costs (with `amounts`); payouts and net only with `details` too. */
        public ?IncidentCosts $costs = null,
    ) {
    }

    public static function of(Incident $incident, bool $details, bool $amounts, ?IncidentCosts $costs = null): self
    {
        $data = $incident->data;
        $claim = $data->claim;
        $summary = [
            'id' => $incident->id,
            'vehicleId' => $incident->vehicleId,
            'createdBy' => $incident->createdBy,
            'occurredOn' => $data->occurredOn,
            'type' => $data->type,
            'damageAreas' => $data->damageAreas,
            'severity' => $data->severity,
            'writeOff' => $data->writeOff,
            'status' => $data->status,
            'closedOn' => $data->closedOn,
            'details' => $details,
            'amounts' => $amounts,
            'costs' => $amounts ? $costs : null,
        ];
        if (!$details) {
            return new self(...$summary);
        }

        return new self(...$summary + [
            'occurredAtTime' => $data->occurredAtTime,
            'location' => $data->location,
            'fault' => $data->fault,
            'description' => $data->description,
            'driverUserId' => $data->driverUserId,
            'driverName' => $data->driverName,
            'otherPartyName' => $data->otherPartyName,
            'otherPartyRegistration' => $data->otherPartyRegistration,
            'otherPartyInsurer' => $data->otherPartyInsurer,
            'policeReference' => $data->policeReference,
            'notes' => $data->notes,
            'claimStatus' => $claim->status,
            'insurer' => $claim->insurer,
            'insuranceDocumentId' => $claim->insuranceDocumentId,
            'claimNumber' => $claim->claimNumber,
            'ncdAffected' => $claim->ncdAffected,
            'claimUpdatedOn' => $claim->updatedOn,
            'excess' => $amounts ? $claim->excess : null,
            'payout' => $amounts ? $claim->payout : null,
            'repairEstimate' => $amounts ? $claim->repairEstimate : null,
        ]);
    }

    /**
     * Payouts and the net cost: the payout is a claim detail.
     */
    public function showsPayouts(): bool
    {
        return $this->details && $this->costs !== null;
    }

    /**
     * The attachment owner this incident's files hang off, as the templates' paperclip expects.
     *
     * @return array{0: string, 1: int}
     */
    public function filesOwner(): array
    {
        return ['incident', $this->id];
    }
}
