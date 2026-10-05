<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\FirstInspectionPrompt;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Service\Vehicle\PaperworkNeedsDate;
use Logbook\Service\Vehicle\PurchaseMileageNeedsDate;
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
 * GET|POST /vehicles/{id}/edit — edit details; upload, replace or remove the
 * photo; add purchase and sale paperwork; the mileage when bought.
 */
final readonly class EditVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private VehicleFormPage $page,
        private Redirector $redirect,
        private ClockInterface $clock,
        private VehiclePaperwork $paperwork,
        private FirstInspectionPrompt $prompt,
        private OdometerWarningFlash $warnings,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $preferences = $user->preferences;

        if ($request->getMethod() !== 'POST') {
            $values = VehicleForm::values($vehicle, $preferences, $this->vehicles->purchaseReading($vehicle)?->readingKm);

            return $this->page->render($request, $response, $values, $vehicle);
        }

        $input = RequestContext::form($request);
        $today = LocalTime::today($this->clock, $preferences->timeZone());
        // Off the form (compliance off, or read-only after the first certificate), the stored date stays.
        $withFirstInspection = $this->page->hasFirstInspectionField($vehicle);
        $edit = VehicleForm::parseEdit($input, $preferences, $today, $withFirstInspection, $vehicle->data->firstInspectionDueOn);
        $files = $this->paperwork->fromRequest($request);
        $photo = VehicleRoute::photo($request);
        $checked = $photo === null ? null : FileUpload::check($photo, $this->vehicles->maxPhotoBytes(), UploadKind::Image);
        $errors = $this->paperwork->errors($edit, $files);

        if ($errors !== null || $edit instanceof ValidationErrors || ($checked !== null && !$checked->isValid())) {
            $errors ??= new ValidationErrors();
            if ($checked !== null && $checked->error !== null) {
                $errors->add('photo', $checked->error, ['max' => $this->vehicles->maxPhotoMegabytes()]);
            }

            return $this->page->render($request, $response, RequestContext::formValues($request), $vehicle, $errors, 422);
        }

        try {
            $updated = $this->vehicles->update($user, $vehicle, $edit->data, $files, $edit->purchaseMileage);
        } catch (TyresBlockTypeChange $refused) {
            $errors = new ValidationErrors();
            $errors->add('type', 'vehicle.error.type_tyres', [
                'type' => $refused->type->value,
                'position' => new TranslatableMessage('tyre.wheel.' . $refused->position->value),
            ]);

            return $this->page->render($request, $response, RequestContext::formValues($request), $vehicle, $errors, 422);
        } catch (PaperworkNeedsDate | PurchaseMileageNeedsDate $refused) {
            $errors = VehiclePaperwork::refusal($refused);

            return $this->page->render($request, $response, RequestContext::formValues($request), $vehicle, $errors, 422);
        }
        if ($photo !== null && $checked !== null) {
            $this->vehicles->replacePhoto($user, $updated, $photo, $checked);
        } elseif (($input['remove_photo'] ?? '') === '1' && $updated->hasPhoto()) {
            $this->vehicles->removePhoto($user, $updated);
        }
        if ($withFirstInspection) {
            // Saved with the field on the form, a cleared date is the owner's choice: no prompt.
            $this->prompt->settle($updated);
        }

        $session = RequestContext::session($request);
        $session->flash('success', 'vehicle.updated', ['name' => $updated->name()]);
        VehicleRoute::flashModelYearWarning($session, $edit->data);
        $this->warnings->queue($session, $this->vehicles->purchaseWarning($updated));

        return $this->redirect->backOr($request, 'vehicles.show', ['id' => (string) $vehicle->id]);
    }
}
