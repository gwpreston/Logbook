<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/odometer/new — add a manual reading. Implausible
 * readings are saved with a warning, never refused.
 */
final readonly class CreateOdometerReadingAction
{
    public function __construct(
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private OdometerFormPage $page,
        private OdometerWarningFlash $warnings,
        private Redirector $redirect,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $preferences = RequestContext::requireUser($request)->preferences;

        if ($request->getMethod() !== 'POST') {
            $defaults = OdometerReadingForm::defaults($this->clock->now(), $preferences);

            return $this->page->render($request, $response, $vehicle, $defaults);
        }

        $data = OdometerReadingForm::parse(RequestContext::form($request), $preferences);
        if ($data instanceof ValidationErrors) {
            return $this->page->render($request, $response, $vehicle, RequestContext::formValues($request), null, $data, 422);
        }

        $reading = $this->odometer->create($vehicle, $data);
        $session = RequestContext::session($request);
        $session->flash('success', 'odometer.created');
        $this->warnings->queue($session, $this->odometer->warningFor($vehicle, $reading->id));

        return $this->redirect->toRoute('odometer.index', ['id' => (string) $vehicle->id]);
    }
}
