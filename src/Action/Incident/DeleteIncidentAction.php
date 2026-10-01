<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Action\EntryGuard;
use Logbook\Service\Incident\IncidentService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET/POST /vehicles/{id}/incidents/{incident}/delete: its linked records
 * are unlinked and kept (spec.md §6 Incident *Links*).
 */
final readonly class DeleteIncidentAction
{
    public function __construct(
        private IncidentService $incidents,
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
        private View $view,
        private Redirector $redirect,
        private EntryGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $incident = IncidentRoute::incident($this->incidents, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $incident->createdBy);
        $description = [
            'date' => $this->formatter->date($incident->data->occurredOn),
            'type' => $this->translator->trans($incident->data->type->labelKey()),
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'incident.delete_title',
                'body' => 'incident.delete_body',
                'params' => $description,
                'action' => ['incidents.delete', ['id' => $vehicle->id, 'incident' => $incident->id]],
                'cancel' => ['incidents.show', ['id' => $vehicle->id, 'incident' => $incident->id]],
            ]);
        }

        $this->incidents->delete($vehicle, $incident);
        RequestContext::session($request)->flash('success', 'incident.deleted', $description);

        return $this->redirect->toRoute('incidents.index', ['id' => (string) $vehicle->id]);
    }
}
