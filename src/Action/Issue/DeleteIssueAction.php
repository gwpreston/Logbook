<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Action\EntryGuard;
use Logbook\Service\Issue\IssueService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/issues/{issue}/delete: the service records that
 * fixed it are kept (spec.md §7.37).
 */
final readonly class DeleteIssueAction
{
    public function __construct(
        private IssueService $issues,
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
        $issue = IssueRoute::issue($this->issues, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $issue->createdBy);
        $refused = IssueRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $description = [
            'date' => $this->formatter->date($issue->data->noticedOn),
            'title' => $issue->data->title,
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'issue.delete_title',
                'body' => 'issue.delete_body',
                'params' => $description,
                'action' => ['issues.delete', ['id' => $vehicle->id, 'issue' => $issue->id]],
                'cancel' => ['issues.show', ['id' => $vehicle->id, 'issue' => $issue->id]],
            ]);
        }

        $this->issues->delete($vehicle, $issue);
        RequestContext::session($request)->flash('success', 'issue.deleted', $description);

        return $this->redirect->toRoute('issues.index', ['id' => (string) $vehicle->id]);
    }
}
