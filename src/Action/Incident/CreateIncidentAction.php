<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Action\Attachment\AttachmentUpload;
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
 * GET/POST /vehicles/{id}/incidents/new — log an incident (spec.md §7.29).
 */
final readonly class CreateIncidentAction
{
    public function __construct(
        private IncidentService $incidents,
        private IncidentFormPage $page,
        private AttachmentUpload $upload,
        private Redirector $redirect,
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
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);

        if ($vehicle->isArchived()) {
            RequestContext::session($request)->flash('error', 'incident.error.archived');

            return $this->redirect->toRoute('incidents.index', ['id' => (string) $vehicle->id]);
        }

        if ($request->getMethod() !== 'POST') {
            // *Use the policy for this date* (no JS): the date and its policy.
            $on = $request->getQueryParams()['on'] ?? null;
            $date = is_string($on) ? LocalTime::parseDate($on) : null;
            $date = $date !== null && $date <= $today ? $date : $today;
            $values = IncidentForm::defaults($date, $this->incidents->policyOn($vehicle, $date));

            return $this->page->render($request, $response, $user, $vehicle, $values);
        }

        $input = IncidentForm::parse(
            RequestContext::form($request),
            $user->preferences,
            $today,
            array_keys($this->page->drivers($vehicle)),
            array_map(static fn (ComplianceDocument $policy): int => $policy->id, $this->page->policies($vehicle)),
        );
        $files = $this->upload->fromRequest($request, owner: AttachmentOwner::Incident);
        $errors = $this->upload->errors($input, $files);
        if ($errors !== null || $input instanceof ValidationErrors) {
            $values = IncidentRoute::formValues($request);

            return $this->page->render($request, $response, $user, $vehicle, $values, null, $errors, 422);
        }

        $incident = $this->incidents->create($vehicle, $input->data, $input->odometerKm, $zone, $files);
        RequestContext::session($request)->flash('success', 'incident.created');

        return $this->redirect->backOr($request, 'incidents.show', [
            'id' => (string) $vehicle->id,
            'incident' => (string) $incident->id,
        ]);
    }
}
