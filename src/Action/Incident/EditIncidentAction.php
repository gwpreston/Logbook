<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\EntryGuard;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Service\Incident\IncidentForm;
use Logbook\Service\Incident\IncidentService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/incidents/{incident}/edit (spec.md §7.29):
 * `Manage`, or `Log` for an incident the user logged.
 */
final readonly class EditIncidentAction
{
    public function __construct(
        private IncidentService $incidents,
        private IncidentFormPage $page,
        private AttachmentUpload $upload,
        private Redirector $redirect,
        private EntryGuard $guard,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $incident = IncidentRoute::incident($this->incidents, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $incident->createdBy);
        $zone = $user->preferences->timeZone();

        if ($request->getMethod() !== 'POST') {
            $values = IncidentForm::values($incident, $this->incidents->odometerOf($vehicle, $incident), $user->preferences);

            return $this->page->render($request, $response, $user, $vehicle, $values, $incident);
        }

        $input = IncidentForm::parse(
            RequestContext::form($request),
            $user->preferences,
            LocalTime::today($this->clock, $zone),
            array_keys($this->page->drivers($vehicle)),
            array_map(static fn (ComplianceDocument $policy): int => $policy->id, $this->page->policies($vehicle)),
        );
        $files = $this->upload->fromRequest($request, owner: AttachmentOwner::Incident);
        $errors = $this->upload->errors($input, $files);
        if ($errors !== null || $input instanceof ValidationErrors) {
            $values = IncidentRoute::formValues($request);

            return $this->page->render($request, $response, $user, $vehicle, $values, $incident, $errors, 422);
        }

        $this->incidents->update($vehicle, $incident, $input->data, $input->odometerKm, $zone, $files);
        RequestContext::session($request)->flash('success', 'incident.updated');

        return $this->redirect->backOr($request, 'incidents.show', [
            'id' => (string) $vehicle->id,
            'incident' => (string) $incident->id,
        ]);
    }
}
