<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\Incident\Incident;
use Logbook\Repository\IncidentRepository;
use Logbook\Repository\IssueRepository;
use Logbook\Domain\Issue\IssueStatus;
use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentStatus;
use Logbook\Service\Compliance\FirstInspection;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\History\ActivityItem;
use Logbook\Service\History\ActivityKind;
use Logbook\Service\History\ActivityQuery;
use Logbook\Service\History\Milestone;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\I18n\Region;
use Logbook\Support\Money\Money;
use Psr\Clock\ClockInterface;

/**
 * Assembles the sale pack (spec.md §7.19) from the sources History, the
 * Mileage tab, *Coming up* and Tyres read: ActivityFeed for the history,
 * the odometer series for the mileage record, ComingUp for *Due next*, the
 * fitted tyres and the current documents. Nothing is stored.
 *
 * Only the kinds a buyer should see are read from the feed (milestones,
 * service records, documents, tyre changes), and of the documents only
 * inspection and pollution certificates are kept: insurance, registration
 * and `other` documents are about the seller. Of the milestones, *First
 * registered* and *Bought* head the pack, never with a price.
 */
final readonly class SalePackBuilder
{
    /** Printed as plain text on the summary for GB owners; never fetched. Checked at each release. */
    public const string MOT_HISTORY_URL = 'https://www.gov.uk/check-mot-history';
    /** How many *Coming up* items the summary shows. */
    public const int DUE_NEXT = 5;

    public function __construct(
        private VehicleService $vehicles,
        private ActivityFeed $feed,
        private OdometerService $odometer,
        private AttachmentService $attachments,
        private ComplianceService $compliance,
        private MaintenanceEntryRepository $maintenance,
        private TyreRepository $tyreRepository,
        private TyreService $tyres,
        private ComingUp $comingUp,
        private PaperworkSelector $paperwork,
        private FeatureToggles $features,
        private ClockInterface $clock,
        private IncidentRepository $incidents,
        private IssueRepository $issues,
    ) {
    }

    public function build(User $user, Vehicle $vehicle, SalePackOptions $options): SalePack
    {
        $enabled = $this->features->all();
        $maintenanceOn = $enabled[Feature::Maintenance->value];
        $complianceOn = $enabled[Feature::Compliance->value];
        $tyresOn = $enabled[Feature::Tyres->value];
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        $history = $this->odometer->history($vehicle);
        $latest = $history->latest();

        $entries = [];
        if ($maintenanceOn) {
            foreach ($this->maintenance->listForVehicle($vehicle->id) as $entry) {
                $entries[$entry->id] = $entry;
            }
        }
        $documents = [];
        if ($complianceOn) {
            foreach ($this->compliance->list($vehicle) as $document) {
                $documents[$document->id] = $document;
            }
        }
        $changes = [];
        if ($tyresOn) {
            foreach ($this->tyreRepository->listChanges($vehicle->id) as $change) {
                $changes[$change->id] = $change;
            }
        }

        $items = $this->feed->items($user, new ActivityQuery(
            [$vehicle],
            [ActivityKind::Milestone, ActivityKind::Maintenance, ActivityKind::Document, ActivityKind::Tyre],
        ));
        $items = array_values(array_filter($items, static fn (ActivityItem $item): bool => match ($item->kind) {
            ActivityKind::Milestone => in_array($item->milestone, [Milestone::FirstRegistered, Milestone::Bought], true),
            ActivityKind::Document => self::isInspection(($documents[$item->entryId] ?? null)?->data->type),
            ActivityKind::Maintenance, ActivityKind::Tyre => true,
            default => false,
        }));
        $of = static fn (ActivityKind $kind): array => array_values(array_filter(
            $items,
            static fn (ActivityItem $item): bool => $item->kind === $kind,
        ));
        $services = $of(ActivityKind::Maintenance);
        [$incidents, $writeOff] = $enabled[Feature::Incidents->value]
            ? $this->incidents($vehicle, $options, $entries)
            : [null, null];

        return new SalePack(
            vehicle: $vehicle,
            options: $options,
            today: $today,
            age: VehicleAge::of($vehicle, $today),
            latest: $latest,
            kmPerYear: VehicleAge::lifetimeAverageKmPerYear($vehicle, $latest, $zone),
            ownership: OwnershipSpan::of($vehicle, $history->readings, $zone),
            servicing: $maintenanceOn ? self::servicing($services, $entries) : null,
            compliance: $complianceOn,
            inspections: $complianceOn ? $this->inspections($vehicle, $today) : [],
            firstInspection: $complianceOn ? FirstInspection::pending($vehicle, $this->compliance->list($vehicle)) : null,
            motHistory: $complianceOn
                && Region::of($user->preferences->locale) === 'GB'
                && ($vehicle->data->registration ?? '') !== '',
            tyres: $tyresOn ? $this->tyres->fitted($vehicle, $user) : [],
            dueNext: $options->dueNext && !$vehicle->isArchived() ? $this->dueNext($user, $vehicle) : null,
            paperwork: $this->paperwork->select($user, $vehicle, $options),
            mileage: MileageEvidence::select(
                $history,
                $this->attachments->counts($vehicle),
                $enabled,
                $entries,
                $documents,
                $changes,
            ),
            milestones: array_reverse($of(ActivityKind::Milestone)),
            services: $services,
            documents: $of(ActivityKind::Document),
            tyreChanges: $of(ActivityKind::Tyre),
            timeline: $options->timeline ? $items : null,
            descriptions: $options->descriptions ? self::descriptions($entries) : [],
            attachments: $this->attachments->index($vehicle),
            workCost: $options->costs ? self::workCost($services, $currency) : null,
            currency: $currency,
            incidents: $incidents,
            writeOff: $writeOff?->data->writeOff,
            writeOffOn: $writeOff?->data->occurredOn,
            openIssues: $enabled[Feature::Issues->value] && $options->openIssues
                ? $this->issues->listForVehicle($vehicle->id, [IssueStatus::Open, IssueStatus::Watching])
                : null,
        );
    }

    /**
     * The *Incidents* group (with the option on) and the latest incident
     * with a write-off category: repairs are the linked service records,
     * by date and vendor; nothing about the claim (spec.md §7.29).
     *
     * @param array<int, MaintenanceEntry> $entries the vehicle's service records (maintenance on)
     * @return array{0: list<SalePackIncident>|null, 1: Incident|null}
     */
    private function incidents(Vehicle $vehicle, SalePackOptions $options, array $entries): array
    {
        $all = $this->incidents->listForVehicles([$vehicle->id]);
        $writeOff = null;
        foreach ($all as $incident) {
            if ($incident->data->writeOff->isWrittenOff()) {
                $writeOff = $incident;
                break;
            }
        }
        if (!$options->incidents) {
            return [null, $writeOff];
        }

        $repairs = [];
        foreach ($entries as $entry) {
            if ($entry->incidentId !== null) {
                $repairs[$entry->incidentId][] = [
                    'date' => $entry->data->performedOn,
                    'title' => $entry->data->title,
                    'vendor' => $entry->data->vendor,
                ];
            }
        }
        $group = [];
        foreach ($all as $incident) {
            $own = $repairs[$incident->id] ?? [];
            usort($own, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            $group[] = new SalePackIncident(
                $incident->data->occurredOn,
                $incident->data->type,
                $incident->data->damageAreas,
                $incident->data->severity,
                $own,
            );
        }

        return [$group, $writeOff];
    }

    public static function isInspection(?ComplianceType $type): bool
    {
        return $type === ComplianceType::Inspection || $type === ComplianceType::Pollution;
    }

    /**
     * @param list<ActivityItem> $services
     * @param array<int, MaintenanceEntry> $entries
     */
    private static function servicing(array $services, array $entries): ServicingSummary
    {
        $last = null;
        foreach ($entries as $entry) {
            $category = $entry->data->category;
            if (
                ($category === MaintenanceCategory::Service || $category === MaintenanceCategory::Oil)
                && ($last === null || [$entry->data->performedOn, $entry->id] > [$last->data->performedOn, $last->id])
            ) {
                $last = $entry;
            }
        }

        return new ServicingSummary(
            records: count($services),
            lastService: $last,
            withPaperwork: count(array_filter($services, static fn (ActivityItem $item): bool => $item->files > 0)),
        );
    }

    /**
     * @return list<InspectionLine>
     */
    private function inspections(Vehicle $vehicle, DateTimeImmutable $today): array
    {
        $lines = [];
        foreach ($this->compliance->states($vehicle, $today) as $state) {
            $data = $state->document->data;
            if (!$state->status->isCurrent() || !self::isInspection($data->type) || $data->expiryOn === null) {
                continue;
            }
            $lines[] = new InspectionLine($data->type, $data->expiryOn, $state->status === DocumentStatus::Expired);
        }
        usort($lines, static fn (InspectionLine $a, InspectionLine $b): int => $a->type->value <=> $b->type->value);

        return $lines;
    }

    /**
     * Up to DUE_NEXT dated items of the next 12 months, overdue first. Their
     * costs are there but never shown. Never finance (spec.md §7.32 *Access*).
     *
     * @return list<ForecastItem>
     */
    private function dueNext(User $user, Vehicle $vehicle): array
    {
        $forecast = $this->comingUp->forecast($user, [$vehicle]);
        $items = $forecast->overdue;
        foreach ($forecast->months as $month) {
            array_push($items, ...$month->items);
        }

        $items = array_values(array_filter($items, static fn (ForecastItem $item): bool => $item->finance === null));

        return array_slice($items, 0, self::DUE_NEXT);
    }

    /**
     * @param array<int, MaintenanceEntry> $entries
     * @return array<int, string>
     */
    private static function descriptions(array $entries): array
    {
        $descriptions = [];
        foreach ($entries as $id => $entry) {
            $text = trim($entry->data->description ?? '');
            if ($text !== '') {
                $descriptions[$id] = $text;
            }
        }

        return $descriptions;
    }

    /**
     * @param list<ActivityItem> $services
     */
    private static function workCost(array $services, string $currency): ?WorkCost
    {
        $total = Money::zero($currency);
        $since = null;
        foreach ($services as $item) {
            if ($item->amount === null) {
                continue;
            }
            $total = $total->add(Money::of($item->amount, $currency));
            $since = $since === null || $item->date < $since ? $item->date : $since;
        }

        return $since === null ? null : new WorkCost($total, $since);
    }
}
