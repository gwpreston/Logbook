<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Service\MotHistory\MotHistoryProvider;
use DateTimeImmutable;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attachment\AttachmentIndex;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\History\ActivityItem;
use Logbook\Service\Tyre\TyreView;
use Logbook\Service\Vehicle\VehicleAge;

/**
 * Everything the sale pack shows (spec.md §7.19), derived on every read and
 * never stored. What a buyer must never see is simply not here: no purchase
 * or sale price, no fuel, expenses, valuations or ownership costs. Work
 * costs are here only with `costs=1` (WorkCost, and the rows' amounts,
 * which the template shows only then).
 */
final readonly class SalePack
{
    /**
     * @param list<InspectionLine> $inspections
     * @param list<TyreView> $tyres the fitted tyres (none with `tyres` off)
     * @param list<ForecastItem>|null $dueNext null when the block is left out
     * @param list<EvidenceReading> $mileage oldest first
     * @param list<ActivityItem> $milestones *First registered* and *Bought*, oldest first
     * @param list<ActivityItem> $services every maintenance record, newest first
     * @param list<ActivityItem> $documents inspection and pollution documents, newest first
     * @param list<ActivityItem> $tyreChanges tyre changes not linked to a record, newest first
     * @param list<ActivityItem>|null $timeline the full timeline, newest first, when asked for
     * @param array<int, string> $descriptions service descriptions by entry id (empty with the option off)
     */
    public function __construct(
        public Vehicle $vehicle,
        public SalePackOptions $options,
        public DateTimeImmutable $today,
        public ?VehicleAge $age,
        public ?OdometerReading $latest,
        public ?float $kmPerYear,
        public ?OwnershipSpan $ownership,
        public ?ServicingSummary $servicing,
        public bool $compliance,
        public array $inspections,
        public bool $motHistory,
        public array $tyres,
        public ?array $dueNext,
        public PaperworkSelection $paperwork,
        public array $mileage,
        public array $milestones,
        public array $services,
        public array $documents,
        public array $tyreChanges,
        public ?array $timeline,
        public array $descriptions,
        public AttachmentIndex $attachments,
        public ?WorkCost $workCost,
        public string $currency,
        /** *First MOT due* while there is no inspection document (Phase 21.2); null with `compliance` off. */
        public ?DateTimeImmutable $firstInspection = null,
        /**
         * The *Incidents* group, newest first, with *Include incidents* (Phase 27.1); null otherwise.
         *
         * @var list<SalePackIncident>|null
         */
        public ?array $incidents = null,
        /** The latest write-off on record (`incidents` on), shown or noticed (spec.md §7.29). */
        public ?WriteOffCategory $writeOff = null,
        public ?DateTimeImmutable $writeOffOn = null,
        /**
         * Open and watching issues, safety first, with *Include open issues*
         * (Phase 40.1, #312); null otherwise. Honest disclosure is the seller's choice.
         *
         * @var list<Issue>|null
         */
        public ?array $openIssues = null,
        /**
         * The MOT tests fetched from DVSA, newest first (Phase 41, spec.md
         * §7.38): date, result and mileage, with the provider's attribution;
         * null while MOT history is off or nothing is fetched.
         *
         * @var list<MotTest>|null
         */
        public ?array $motTests = null,
        public ?MotHistoryProvider $motProvider = null,
    ) {
    }

    /**
     * The cover page with the vehicle photo (Phase 21.1): only when asked
     * for with `photo=1`, and only when there is a photo.
     */
    public function hasCover(): bool
    {
        return $this->options->photo && $this->vehicle->hasPhoto();
    }

    public function hasMileageWarnings(): bool
    {
        return MileageEvidence::hasWarnings($this->mileage);
    }
}
