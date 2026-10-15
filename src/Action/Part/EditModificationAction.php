<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\EntryGuard;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Part\ModificationForm;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\RecordPartsForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/modifications/{modification}/edit (spec.md
 * §7.39): `Manage`, or `Log` for one the user logged.
 */
final readonly class EditModificationAction
{
    public function __construct(
        private ModificationService $modifications,
        private ModificationFormPage $page,
        private RecordPartsPicker $parts,
        private CostInput $costs,
        private AttachmentUpload $upload,
        private Redirector $redirect,
        private EntryGuard $guard,
        private ClockInterface $clock,
        private OdometerWarningFlash $warnings,
        private OdometerService $odometer,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $modification = PartRoute::modification($this->modifications, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $modification->createdBy);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        if ($request->getMethod() !== 'POST') {
            $values = ModificationForm::values($modification, $user->preferences)
                + $this->parts->values($vehicle, PartService::MODIFICATION, $modification->id);

            return $this->page->render($request, $response, $vehicle, $values, $modification);
        }

        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $input = $this->costs->guard(
            $request,
            $vehicle,
            RequestContext::form($request),
            ModificationForm::values($modification, $user->preferences)
                + $this->parts->values($vehicle, PartService::MODIFICATION, $modification->id),
        );
        $values = RequestContext::formValues($request);
        if (RecordPartsForm::more($input) !== null) {
            return $this->page->render($request, $response, $vehicle, $values, $modification);
        }
        $data = ModificationForm::parse($input, $user->preferences, $today, $modification);
        $files = $this->upload->fromRequest($request, owner: AttachmentOwner::Modification);
        $errors = $this->upload->errors($data, $files);
        $parts = $this->parts->read($vehicle, PartService::MODIFICATION, $modification->id, $input, $user->preferences, $today);
        if ($parts instanceof ValidationErrors) {
            $errors = ($errors ?? ($data instanceof ValidationErrors ? $data : new ValidationErrors()))->merge($parts);
        }
        if ($errors !== null || $data instanceof ValidationErrors || $parts instanceof ValidationErrors) {
            return $this->page->render(
                $request,
                $response,
                $vehicle,
                $values,
                $modification,
                $errors ?? ($data instanceof ValidationErrors ? $data : null),
                422,
            );
        }

        try {
            $saved = $this->modifications->update($vehicle, $modification, $data, $zone, $files, $parts);
        } catch (PartChangeRefused $refusal) {
            $errors = PartRoute::refusal($refusal, $this->formatter);

            return $this->page->render($request, $response, $vehicle, $values, $modification, $errors, 422);
        }
        $session = RequestContext::session($request);
        $session->flash('success', 'modification.updated', ['title' => $saved->data->title]);
        $this->warnings->queue($session, $this->odometer->warningForEntry($vehicle, OdometerSource::Modification, $saved->id));

        return $this->redirect->backOr($request, 'modifications.show', [
            'id' => (string) $vehicle->id,
            'modification' => (string) $saved->id,
        ]);
    }
}
