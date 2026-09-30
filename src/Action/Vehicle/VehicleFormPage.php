<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Compliance\FirstInspection;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelPicker;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\FirstInspectionPrompt;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\InspectionRules;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\FormOptions;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit vehicle form (shared by both Actions).
 */
final readonly class VehicleFormPage
{
    public function __construct(
        private View $view,
        private AppSettings $settings,
        private OdometerService $odometer,
        private ClockInterface $clock,
        private VehiclePaperwork $paperwork,
        private FeatureToggles $features,
        private FirstInspection $firstInspection,
        private FirstInspectionPrompt $prompt,
    ) {
    }

    /**
     * Whether the form carries an editable *First MOT due* (spec.md §7.1):
     * with `compliance` on, and until the vehicle's first certificate.
     */
    public function hasFirstInspectionField(?Vehicle $vehicle): bool
    {
        return $this->features->isEnabled(Feature::Compliance)
            && ($vehicle === null || !$this->firstInspection->vehicleHasCertificate($vehicle));
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?Vehicle $vehicle = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $todayDate = LocalTime::today($this->clock, $user->preferences->timeZone());
        $today = $todayDate->format('Y-m-d');
        // The suggestion follows the owner's locale (the adding user's for a new vehicle).
        $ownerLocale = $vehicle === null ? $user->preferences->locale : $this->prompt->ownerLocale($user, $vehicle);
        $compliance = $this->features->isEnabled(Feature::Compliance);

        return $this->view->render($request, $response, 'vehicles/form.twig', $this->paperwork->formContext($vehicle) + [
            'vehicle' => $vehicle,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'types' => VehicleType::cases(),
            'fuel_types' => FuelType::cases(),
            'grade_groups' => FuelPicker::defaultGradeGroups($user->preferences->locale),
            // Which family's grades fit each fuel type, for the form's script.
            'grade_families' => array_combine(
                array_map(static fn (FuelType $t): string => $t->value, FuelType::cases()),
                array_map(static fn (FuelType $t): ?string => FuelGrade::defaultFamilyFor($t)?->value, FuelType::cases()),
            ),
            'capacity_labels' => array_combine(
                array_map(static fn (FuelType $t): string => $t->value, FuelType::cases()),
                array_map(static fn (FuelType $t): string => $t->capacityLabelKey(), FuelType::cases()),
            ),
            'currency_options' => FormOptions::currencies(RequestContext::locale($request)),
            'default_currency' => $user->preferences->currency,
            'max_upload_mb' => $this->settings->maxUploadMb,
            'first_year' => VehicleForm::FIRST_YEAR,
            'first_registration' => VehicleForm::FIRST_REGISTRATION,
            'last_registration' => $today,
            // Edit only: the current odometer, read-only (spec.md §7.1).
            'current_reading' => $vehicle === null ? null : $this->odometer->history($vehicle)->latest(),
            'first_inspection' => [
                'shown' => $compliance,
                'editable' => $this->hasFirstInspectionField($vehicle),
                // Read-only once a certificate exists: the one that now sets the next MOT.
                'certificate' => $compliance && $vehicle !== null
                    ? $this->firstInspection->currentCertificate($vehicle, $todayDate)
                    : null,
                'months' => InspectionRules::months($ownerLocale),
                'hint' => InspectionRules::hintKey($ownerLocale),
                'marker' => VehicleForm::FIRST_INSPECTION_JS,
            ],
        ], $status);
    }
}
