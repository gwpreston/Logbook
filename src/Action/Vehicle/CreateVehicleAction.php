<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\PaperworkNeedsDate;
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
 * GET|POST /vehicles/new — add a vehicle, optionally with a photo, its
 * current odometer and the date it was read (written as its first reading)
 * and its purchase and sale paperwork.
 */
final readonly class CreateVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private VehicleFormPage $page,
        private Redirector $redirect,
        private ClockInterface $clock,
        private VehiclePaperwork $paperwork,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $preferences = $user->preferences;
        $today = LocalTime::today($this->clock, $preferences->timeZone());

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, VehicleForm::defaults($today));
        }

        $new = VehicleForm::parseNew(RequestContext::form($request), $preferences, $today);
        $files = $this->paperwork->fromRequest($request);
        $photo = VehicleRoute::photo($request);
        $checked = $photo === null ? null : FileUpload::check($photo, $this->vehicles->maxPhotoBytes(), UploadKind::Image);
        $errors = $this->paperwork->errors($new, $files);

        if ($errors !== null || $new instanceof ValidationErrors || ($checked !== null && !$checked->isValid())) {
            $errors ??= new ValidationErrors();
            if ($checked !== null && $checked->error !== null) {
                $errors->add('photo', $checked->error, ['max' => $this->vehicles->maxPhotoMegabytes()]);
            }

            return $this->page->render($request, $response, RequestContext::formValues($request), null, $errors, 422);
        }

        try {
            $vehicle = $this->vehicles->create($user, $new->data, $new->startingReading, $files);
        } catch (PaperworkNeedsDate $refused) {
            $errors = VehiclePaperwork::refusal($refused);

            return $this->page->render($request, $response, RequestContext::formValues($request), null, $errors, 422);
        }
        if ($photo !== null && $checked !== null) {
            $this->vehicles->replacePhoto($user, $vehicle, $photo, $checked);
        }

        $session = RequestContext::session($request);
        $session->flash('success', 'vehicle.created', ['name' => $vehicle->name()]);
        VehicleRoute::flashModelYearWarning($session, $new->data);
        if (VehicleForm::startingReadingWarning($new)) {
            $session->flash('warning', 'vehicle.reading_before_registration');
        }

        return $this->redirect->toRoute('vehicles.show', ['id' => (string) $vehicle->id]);
    }
}
