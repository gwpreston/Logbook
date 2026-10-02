<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attention\AttentionList;
use Logbook\Service\Attention\AttentionSettingsStore;
use Logbook\Service\Attention\AttentionWording;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Compliance\FirstInspection;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Forecast\ComingUp;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\History\ActivityFeed;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Report\OwnershipService;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\FirstInspectionPrompt;
use Logbook\Service\Vehicle\VehicleAge;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Logbook\Service\Finance\FinanceService;

/**
 * GET /vehicles/{id} — vehicle overview: current odometer and fuel figures,
 * the latest history, what maintenance is due next, where each document
 * stands, the tyres fitted, and the vehicle's details and ownership (with
 * paperclips for the purchase and sale paperwork, the latest value, the
 * depreciation and the value over time), its cost of ownership, and what
 * is coming up in the next 12 months, with what needs attention first. Before the first MOT certificate, the
 * documents card shows the *First MOT due* date, or offers to set one.
 * Each area has its own tab.
 */
final readonly class ShowVehicleAction
{
    private const int RECENT_HISTORY = 5;
    private const int SCHEDULES_SHOWN = 3;

    public function __construct(
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private FuelService $fuel,
        private MaintenanceService $maintenance,
        private ScheduleService $schedules,
        private ComplianceService $compliance,
        private View $view,
        private ClockInterface $clock,
        private ReminderSettingsStore $reminderSettings,
        private ActivityFeed $feed,
        private TyreService $tyres,
        private FeatureToggles $features,
        private AttachmentService $attachments,
        private ValuationService $valuations,
        private ValueChart $valueChart,
        private OwnershipService $ownership,
        private ComingUp $comingUp,
        private ForecastWording $forecastWording,
        private FirstInspection $firstInspection,
        private FirstInspectionPrompt $firstInspectionPrompt,
        private AttentionList $attention,
        private AttentionWording $attentionWording,
        private AttentionSettingsStore $attentionSettings,
        private FinanceService $finance,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $odometer = $this->odometer->history($vehicle);
        // The owner's lead times, as the vehicle's reminders use (Phase 19).
        $lead = $this->reminderSettings->reminderPreferences($vehicle->userId);
        $fuel = $this->fuel->history($vehicle);
        $kind = Fuel::defaultFor($vehicle->data->fuelType)->kind();
        $documents = array_filter(
            $this->compliance->states($vehicle, $today, $lead->documentDays),
            static fn (DocumentState $s): bool => $s->status->isCurrent(),
        );
        $compliance = $this->features->isEnabled(Feature::Compliance);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $valuations = $this->valuations->forVehicle($vehicle);
        $zone = $user->preferences->timeZone();
        // The owner's threshold for the stale-value hint, as Needs attention uses (spec.md §7.24).
        $depreciation = Depreciation::of(
            $vehicle,
            $valuations,
            $odometer->readings,
            $today,
            $zone,
            $currency,
            $this->attentionSettings->thresholds($vehicle->userId)->valuationMonths,
        );
        // Core too; an archived vehicle raises nothing (spec.md §7.18, §7.24).
        $comingUp = $vehicle->isArchived() ? null : $this->comingUp->forecast($user, [$vehicle]);

        return $this->view->render($request, $response, 'vehicles/show.twig', [
            'vehicle' => $vehicle,
            'currency' => $currency,
            'odometer' => $odometer,
            'fuel' => $fuel,
            'fuel_summary' => $fuel->summary($kind),
            'electric' => $kind === EnergyKind::Electric,
            'maintenance' => $this->maintenance->history($vehicle),
            'schedules' => array_slice(
                $this->schedules->states($vehicle, $today, $odometer, $lead->scheduleDays, $lead->scheduleKm),
                0,
                self::SCHEDULES_SHOWN,
            ),
            'documents' => array_values($documents),
            'first_inspection' => $compliance ? $this->firstInspection->due($vehicle, $today, $lead->documentDays) : null,
            'first_inspection_prompt' => $this->firstInspectionPrompt->suggestion($user, $vehicle, $today),
            'age' => VehicleAge::of($vehicle, $today),
            'paperwork' => $this->attachments->countsFor([$vehicle->id], [
                AttachmentOwner::Purchase->value => [$vehicle->id],
                AttachmentOwner::Sale->value => [$vehicle->id],
            ]),
            'depreciation' => $depreciation,
            'value_chart' => $this->valueChart->build($depreciation, $user->preferences),
            'has_valuations' => $valuations !== [],
            // Finance (Phase 29.1, spec.md §7.32 *Overview card*): the active agreement, for those who may see it.
            'finance' => $this->finance->activeView($user, $vehicle),
            // Core, like the Expenses tab: shown whatever modules are on (spec.md §7.1).
            'ownership_cost' => $this->ownership->forVehicle($user, $vehicle, $odometer->readings, $depreciation, $today),
            'coming_up' => $comingUp,
            'attention' => $comingUp === null ? [] : $this->attention->forVehicles($user, [$vehicle], forecast: $comingUp)->items,
            'attention_wording' => $this->attentionWording,
            'forecast_wording' => $this->forecastWording,
            'recent_history' => $this->feed->latest($user, [$vehicle], self::RECENT_HISTORY),
            // The Tyres card is hidden while the vehicle has no tyres (spec.md §7.17).
            'tyres' => $this->features->isEnabled(Feature::Tyres) && $this->tyres->hasTyres($vehicle)
                ? $this->tyres->overview($vehicle, $user)
                : null,
        ]);
    }
}
