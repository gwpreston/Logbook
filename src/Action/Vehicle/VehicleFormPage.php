<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\FormOptions;
use Logbook\Support\View\View;
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
    ) {
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

        return $this->view->render($request, $response, 'vehicles/form.twig', [
            'vehicle' => $vehicle,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'types' => VehicleType::cases(),
            'fuel_types' => FuelType::cases(),
            'currency_options' => FormOptions::currencies(RequestContext::locale($request)),
            'default_currency' => $user->preferences->currency,
            'max_upload_mb' => $this->settings->maxUploadMb,
            'first_year' => VehicleForm::FIRST_YEAR,
        ], $status);
    }
}
