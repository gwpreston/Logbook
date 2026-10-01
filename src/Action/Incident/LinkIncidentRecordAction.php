<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Domain\Incident\LinkKind;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Service\Incident\IncidentRecords;
use Logbook\Service\Incident\IncidentService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;

/**
 * POST /vehicles/{id}/incidents/{incident}/links — *Link a record* (a
 * `record` of the picker, "maintenance:12") or *Unlink* (`unlink`, the
 * same form of value). The user must be able to change the incident and
 * the record. A record already linked elsewhere, outside the picker's
 * window, or a tyre change that follows its service record, is refused.
 */
final readonly class LinkIncidentRecordAction
{
    public function __construct(
        private IncidentService $incidents,
        private IncidentAccess $access,
        private IncidentRecords $records,
        private EntryAccess $entries,
        private Redirector $redirect,
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
        if (!$this->access->canChange($user, $vehicle, $incident) || $vehicle->isArchived()) {
            throw new HttpForbiddenException($request);
        }
        $form = RequestContext::form($request);
        $unlink = is_string($form['unlink'] ?? null) ? $form['unlink'] : null;
        $value = $unlink ?? (is_string($form['record'] ?? null) ? $form['record'] : '');
        $session = RequestContext::session($request);
        $back = ['id' => (string) $vehicle->id, 'incident' => (string) $incident->id];

        [$kindCode, $id] = array_pad(explode(':', $value, 2), 2, '');
        $kind = LinkKind::tryFrom($kindCode);
        $record = $kind === null || !ctype_digit($id)
            ? null
            : IncidentRecords::find($this->records->all($user, $vehicle), $kind, (int) $id);
        $allowed = $record !== null
            && !$record->followsRecord
            && $this->entries->canChange($user, $vehicle, $record->createdBy)
            && ($unlink !== null
                ? $record->incidentId === $incident->id
                : $record->incidentId === null && IncidentRecords::inWindow($incident, $record->date));
        if (!$allowed) {
            $session->flash('error', 'incident.link.refused');

            return $this->redirect->toRoute('incidents.show', $back);
        }

        $this->incidents->link($vehicle, $record->kind, $record->id, $unlink === null ? $incident : null);
        $session->flash('success', $unlink === null ? 'incident.link.linked' : 'incident.link.unlinked');

        return $this->redirect->toRoute('incidents.show', $back);
    }
}
