<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use DateTimeImmutable;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Number\Decimal;

/**
 * Archiving as *Written off* (spec.md §7.29 *Total loss*): offered only
 * for a vehicle with a settled incident that has a write-off category,
 * while the incidents module is on. The settlement is the sale: its date
 * is the incident's closed_on, else the latest claim update, else today,
 * and its price the payout.
 */
final readonly class TotalLoss
{
    public function __construct(
        private IncidentRepository $incidents,
        private FeatureToggles $features,
    ) {
    }

    /**
     * The incidents it could be written off by, the latest settled first.
     *
     * @return list<Incident>
     */
    public function candidates(Vehicle $vehicle): array
    {
        if (!$this->features->isEnabled(Feature::Incidents)) {
            return [];
        }
        $settled = array_values(array_filter(
            $this->incidents->listForVehicles([$vehicle->id]),
            static fn (Incident $incident): bool => $incident->data->writeOff->isWrittenOff()
                && $incident->data->claim->status === ClaimStatus::Settled,
        ));
        usort($settled, static fn (Incident $a, Incident $b): int
            => (self::settledOn($b) <=> self::settledOn($a)) ?: $b->id <=> $a->id);

        return $settled;
    }

    public function isOffered(Vehicle $vehicle): bool
    {
        return !$vehicle->isArchived() && $this->candidates($vehicle) !== [];
    }

    /**
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public static function saleDate(Incident $incident, DateTimeImmutable $today): DateTimeImmutable
    {
        return $incident->data->closedOn ?? $incident->data->claim->updatedOn ?? $today;
    }

    public static function salePrice(Incident $incident): string
    {
        $payout = $incident->data->claim->payout;

        return $payout === null ? '' : Decimal::trim($payout);
    }

    private static function settledOn(Incident $incident): DateTimeImmutable
    {
        return $incident->data->closedOn ?? $incident->data->claim->updatedOn ?? $incident->data->occurredOn;
    }
}
