<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\TyresBlockTypeChange;
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
use Symfony\Component\Translation\TranslatableMessage;

/**
 * GET|POST /vehicles/{id}/edit — edit details; upload, replace or remove the photo.
 */
final readonly class EditVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private VehicleFormPage $page,
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
        $user = RequestContext::requireUser($request);
        $preferences = $user->preferences;

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, VehicleForm::values($vehicle, $preferences), $vehicle);
        }

        $input = RequestContext::form($request);
        $today = LocalTime::today($this->clock, $preferences->timeZone());
        $data = VehicleForm::parse($input, $preferences, $today);
        $photo = VehicleRoute::photo($request);
        $checked = $photo === null ? null : FileUpload::check($photo, $this->vehicles->maxPhotoBytes(), UploadKind::Image);

        if ($data instanceof ValidationErrors || ($checked !== null && !$checked->isValid())) {
            $errors = $data instanceof ValidationErrors ? $data : new ValidationErrors();
            if ($checked !== null && $checked->error !== null) {
                $errors->add('photo', $checked->error, ['max' => $this->vehicles->maxPhotoMegabytes()]);
            }

            return $this->page->render($request, $response, RequestContext::formValues($request), $vehicle, $errors, 422);
        }

        try {
            $updated = $this->vehicles->update($user, $vehicle, $data);
        } catch (TyresBlockTypeChange $refused) {
            $errors = new ValidationErrors();
            $errors->add('type', 'vehicle.error.type_tyres', [
                'type' => $refused->type->value,
                'position' => new TranslatableMessage('tyre.wheel.' . $refused->position->value),
            ]);

            return $this->page->render($request, $response, RequestContext::formValues($request), $vehicle, $errors, 422);
        }
        if ($photo !== null && $checked !== null) {
            $this->vehicles->replacePhoto($user, $updated, $photo, $checked);
        } elseif (($input['remove_photo'] ?? '') === '1' && $updated->hasPhoto()) {
            $this->vehicles->removePhoto($user, $updated);
        }

        $session = RequestContext::session($request);
        $session->flash('success', 'vehicle.updated', ['name' => $updated->name()]);
        VehicleRoute::flashModelYearWarning($session, $data);

        return $this->redirect->backOr($request, 'vehicles.show', ['id' => (string) $vehicle->id]);
    }
}
