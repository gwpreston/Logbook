<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\EntryGuard;
use Logbook\Service\Part\PartService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/parts/{part}/delete: its fittings, their readings
 * and its files go; the records that fitted or removed it stay.
 */
final readonly class DeletePartAction
{
    public function __construct(
        private PartService $parts,
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
        $part = PartRoute::part($this->parts, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $part->createdBy);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $params = ['name' => $part->label()];
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'part.delete_title',
                'body' => 'part.delete_body',
                'params' => $params,
                'action' => ['parts.delete', ['id' => $vehicle->id, 'part' => $part->id]],
                'cancel' => ['parts.show', ['id' => $vehicle->id, 'part' => $part->id]],
            ]);
        }
        $this->parts->delete($vehicle, $part);
        RequestContext::session($request)->flash('success', 'part.deleted', $params);

        return $this->redirect->toRoute('parts.index', ['id' => (string) $vehicle->id], ['status' => 'all']);
    }
}
