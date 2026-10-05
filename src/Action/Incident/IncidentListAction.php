<?php

declare(strict_types=1);

namespace Logbook\Action\Incident;

use Logbook\Domain\Incident\Incident;
use Logbook\Service\Incident\IncidentStats;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Incident\IncidentAccess;
use Logbook\Service\Incident\IncidentService;
use Logbook\Support\View\Pagination;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/incidents — the Incidents tab (spec.md §7.29): the
 * strip, counted over every incident, then the cards, open ones first, then
 * newest first, each as the viewer may see it.
 */
final readonly class IncidentListAction
{
    public function __construct(
        private IncidentService $incidents,
        private IncidentAccess $access,
        private AttachmentService $attachments,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $all = $this->incidents->list($vehicle);
        $costs = $this->incidents->costsFor($user, $vehicle, $all);
        $views = array_map(
            fn (Incident $incident) => $this->access->view($user, $vehicle, $incident, $costs[$incident->id] ?? null),
            $all,
        );
        $pagination = Pagination::fromQuery($request->getQueryParams(), count($views));

        return $this->view->render($request, $response, 'incidents/index.twig', [
            'vehicle' => $vehicle,
            'incidents' => $pagination->slice($views),
            'stats' => IncidentStats::of($views),
            'pagination' => $pagination,
            'attachment_counts' => $this->attachments->counts($vehicle),
        ]);
    }
}
