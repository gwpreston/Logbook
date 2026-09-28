<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/new — add a vehicle, optionally with a photo and its
 * current odometer (written as its first reading).
 */
final readonly class CreateVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private VehicleFormPage $page,
        private Redirector $redirect,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, VehicleForm::defaults());
        }

        $user = RequestContext::requireUser($request);
        $preferences = $user->preferences;
        $today = LocalTime::today($this->clock, $preferences->timeZone());

        $new = VehicleForm::parseNew(RequestContext::form($request), $preferences, $today);
        $photo = VehicleRoute::photo($request);
        $checked = $photo === null ? null : FileUpload::check($photo, $this->vehicles->maxPhotoBytes(), UploadKind::Image);

        if ($new instanceof ValidationErrors || ($checked !== null && !$checked->isValid())) {
            $errors = $new instanceof ValidationErrors ? $new : new ValidationErrors();
            if ($checked !== null && $checked->error !== null) {
                $errors->add('photo', $checked->error, ['max' => $this->vehicles->maxPhotoMegabytes()]);
            }

            return $this->page->render($request, $response, RequestContext::formValues($request), null, $errors, 422);
        }

        $vehicle = $this->vehicles->create($user, $new->data, $new->startingOdometerKm);
        if ($photo !== null && $checked !== null) {
            $this->vehicles->replacePhoto($user, $vehicle, $photo, $checked);
        }

        $session = RequestContext::session($request);
        $session->flash('success', 'vehicle.created', ['name' => $vehicle->name()]);
        VehicleRoute::flashModelYearWarning($session, $new->data);

        return $this->redirect->toRoute('vehicles.show', ['id' => (string) $vehicle->id]);
    }
}
