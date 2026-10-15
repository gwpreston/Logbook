<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\EntryGuard;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/modifications/{modification}/delete: the
 * confirmation names the parts it changes; the fittings it made go and the
 * removals it made are reopened (spec.md §7.39 *Deleting a record*).
 */
final readonly class DeleteModificationAction
{
    public function __construct(
        private ModificationService $modifications,
        private PartService $parts,
        private DisplayFormatter $formatter,
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
        $modification = PartRoute::modification($this->modifications, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $modification->createdBy);
        $refused = PartRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $description = [
            'date' => $this->formatter->date($modification->data->doneOn),
            'title' => $modification->data->title,
        ];
        $changes = array_values(array_unique(array_map(
            static fn (array $row): string => $row['part']->label(),
            $this->parts->byRecord($vehicle, PartService::MODIFICATION, $modification->id),
        )));
        $page = [
            'vehicle' => $vehicle,
            'title' => 'modification.delete_title',
            'body' => 'modification.delete_body',
            'params' => $description,
            'parts' => $changes,
            'action' => ['modifications.delete', ['id' => $vehicle->id, 'modification' => $modification->id]],
            'cancel' => ['modifications.show', ['id' => $vehicle->id, 'modification' => $modification->id]],
        ];
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'parts/delete.twig', $page);
        }

        try {
            $this->modifications->delete($vehicle, $modification);
        } catch (PartChangeRefused $refusal) {
            $error = PartRoute::refusal($refusal, $this->formatter)->all()['form'] ?? null;

            return $this->view->render($request, $response, 'parts/delete.twig', $page + ['refusal' => $error], 422);
        }
        RequestContext::session($request)->flash('success', 'modification.deleted', $description);

        return $this->redirect->toRoute('parts.index', ['id' => (string) $vehicle->id]);
    }
}
