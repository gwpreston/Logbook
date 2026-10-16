<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Ask\DraftPrefill;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Ai\Draft\DraftKind;
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
 * GET/POST /vehicles/{id}/modifications/new — log a modification with the
 * parts it fits and what came off, in one save (spec.md §7.39).
 */
final readonly class CreateModificationAction
{
    public function __construct(
        private ModificationService $modifications,
        private ModificationFormPage $page,
        private RecordPartsPicker $parts,
        private CostInput $costs,
        private AttachmentUpload $upload,
        private Redirector $redirect,
        private ClockInterface $clock,
        private OdometerWarningFlash $warnings,
        private OdometerService $odometer,
        private DisplayFormatter $formatter,
        private DraftPrefill $prefill,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        if ($request->getMethod() !== 'POST') {
            // *Edit* on a draft card (Phase 50.2): the draft's values, marked.
            $values = $this->prefill->values($request, DraftKind::Modification, $vehicle->id, ModificationForm::defaults($today));

            return $this->page->render($request, $response, $vehicle, $values);
        }

        $input = $this->costs->guard($request, $vehicle, RequestContext::form($request));
        $values = RequestContext::formValues($request);
        if (RecordPartsForm::more($input) !== null) {
            return $this->page->render($request, $response, $vehicle, $values);
        }
        $data = ModificationForm::parse($input, $user->preferences, $today);
        $files = $this->upload->fromRequest($request, owner: AttachmentOwner::Modification);
        $errors = $this->upload->errors($data, $files);
        $parts = $this->parts->read($vehicle, PartService::MODIFICATION, null, $input, $user->preferences, $today);
        if ($parts instanceof ValidationErrors) {
            $errors = ($errors ?? ($data instanceof ValidationErrors ? $data : new ValidationErrors()))->merge($parts);
        }
        if ($errors !== null || $data instanceof ValidationErrors || $parts instanceof ValidationErrors) {
            return $this->page->render(
                $request,
                $response,
                $vehicle,
                $values,
                null,
                $errors ?? ($data instanceof ValidationErrors ? $data : null),
                422,
            );
        }

        try {
            $modification = $this->modifications->create($vehicle, $data, $zone, $files, $parts);
        } catch (PartChangeRefused $refusal) {
            return $this->page->render(
                $request,
                $response,
                $vehicle,
                $values,
                null,
                PartRoute::refusal($refusal, $this->formatter),
                422,
            );
        }
        $this->prefill->saved($request);
        $session = RequestContext::session($request);
        $session->flash('success', 'modification.created', ['title' => $modification->data->title]);
        $this->warnings->queue(
            $session,
            $this->odometer->warningForEntry($vehicle, OdometerSource::Modification, $modification->id),
        );

        return $this->redirect->backOr($request, 'modifications.show', [
            'id' => (string) $vehicle->id,
            'modification' => (string) $modification->id,
        ]);
    }
}
