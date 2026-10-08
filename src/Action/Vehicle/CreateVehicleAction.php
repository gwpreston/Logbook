<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\FirstInspectionPrompt;
use Logbook\Service\Vehicle\PaperworkNeedsDate;
use Logbook\Service\Vehicle\PurchaseMileageNeedsDate;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Service\MotHistory\VehicleLookup;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/new — add a vehicle, optionally with a photo, its
 * current odometer and the date it was read (written as its first reading),
 * its mileage when bought and its purchase and sale paperwork. Without JS, a
 * blank *First MOT due* gets the suggestion, and the flash says so (spec.md
 * §7.1). *Look up* (Phase 41) fills blank fields from DVSA: JSON for the
 * form's script, or the form drawn again without JS.
 */
final readonly class CreateVehicleAction
{
    public function __construct(
        private VehicleService $vehicles,
        private VehicleFormPage $page,
        private Redirector $redirect,
        private ClockInterface $clock,
        private VehiclePaperwork $paperwork,
        private FeatureToggles $features,
        private FirstInspectionPrompt $prompt,
        private DisplayFormatter $formatter,
        private OdometerWarningFlash $warnings,
        private VehicleLookup $lookup,
        private TranslatorInterface $translator,
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

        // *Look up* (spec.md §7.38, #326): DVSA's details for the blank fields; nothing is saved.
        // Never a save, even when MOT history was switched off after the page loaded.
        if ((RequestContext::form($request)['lookup'] ?? null) === '1') {
            return $this->lookUp($request, $response);
        }

        $withFirstInspection = $this->features->isEnabled(Feature::Compliance);
        $new = VehicleForm::parseNew(RequestContext::form($request), $preferences, $today, $withFirstInspection);
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
            $vehicle = $this->vehicles->create($user, $new->data, $new->startingReading, $files, $new->purchaseKm);
        } catch (PaperworkNeedsDate | PurchaseMileageNeedsDate $refused) {
            $errors = VehiclePaperwork::refusal($refused);

            return $this->page->render($request, $response, RequestContext::formValues($request), null, $errors, 422);
        }
        if ($photo !== null && $checked !== null) {
            $this->vehicles->replacePhoto($user, $vehicle, $photo, $checked);
        }
        if ($withFirstInspection) {
            // The owner has seen the field: the one-time prompt never asks about this vehicle.
            $this->prompt->settle($vehicle);
        }

        $session = RequestContext::session($request);
        $session->flash('success', 'vehicle.created', ['name' => $vehicle->name()]);
        if ($new->suggestedFirstInspection !== null) {
            $session->flash('info', 'vehicle.first_inspection_set', [
                'date' => $this->formatter->date($new->suggestedFirstInspection),
            ]);
        }
        VehicleRoute::flashModelYearWarning($session, $new->data);
        if (VehicleForm::startingReadingWarning($new)) {
            $session->flash('warning', 'vehicle.reading_before_registration');
        }
        if ($new->purchaseKm !== null) {
            $this->warnings->queue($session, $this->vehicles->purchaseWarning($vehicle));
        }

        return $this->redirect->toRoute('vehicles.show', ['id' => (string) $vehicle->id]);
    }

    private function lookUp(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $values = RequestContext::formValues($request);
        unset($values['lookup']);
        $result = $this->lookup->lookUp($values);
        if ($request->getHeaderLine('X-Lookup') === '1') {
            $response->getBody()->write((string) json_encode([
                'fields' => $result['fields'],
                'message' => $this->translator->trans($result['message'], $result['params']),
            ], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $this->page->render(
            $request,
            $response,
            $result['fields'] + $values,
            null,
            null,
            200,
            ['message' => $result['message'], 'params' => $result['params'], 'filled' => array_keys($result['fields'])],
        );
    }
}
