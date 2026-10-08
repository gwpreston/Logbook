<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Action\EntryGuard;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Issue\IssueUpdate;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Issue\IssueForm;
use Logbook\Service\Issue\IssueService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * The timeline's notes (spec.md §7.37 *Updates*, #316):
 * GET/POST /vehicles/{id}/issues/{issue}/updates/new adds one (`Log`);
 * GET/POST /vehicles/{id}/issue-updates/{update}/edit and …/delete change a
 * note under `EntryAccess`. An automatic status line is never changed: 404.
 */
final readonly class IssueUpdateAction
{
    public function __construct(
        private IssueService $issues,
        private View $view,
        private Redirector $redirect,
        private EntryGuard $guard,
        private ClockInterface $clock,
        private OdometerWarningFlash $warnings,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $refused = IssueRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $mode = $args['mode'] ?? 'new';
        if ($mode === 'new') {
            $issue = IssueRoute::issue($this->issues, $vehicle, $request, $args);
            $update = null;
        } else {
            [$issue, $update] = IssueRoute::update($this->issues, $vehicle, $request, $args);
            if ($update->isAutomatic()) {
                throw new HttpNotFoundException($request);
            }
            $this->guard->allowChange($request, $vehicle, $update->createdBy);
        }
        $zone = $user->preferences->timeZone();
        $back = ['id' => (string) $vehicle->id, 'issue' => (string) $issue->id];

        if ($mode === 'delete' && $update !== null) {
            if ($request->getMethod() !== 'POST') {
                return $this->view->render($request, $response, 'entries/delete.twig', [
                    'vehicle' => $vehicle,
                    'title' => 'issue.update.delete_title',
                    'body' => 'issue.update.delete_body',
                    'params' => ['title' => $issue->data->title],
                    'action' => ['issues.updates.delete', ['id' => $vehicle->id, 'update' => $update->id]],
                    'cancel' => ['issues.show', ['id' => $vehicle->id, 'issue' => $issue->id]],
                ]);
            }
            $this->issues->deleteUpdate($vehicle, $issue, $update);
            RequestContext::session($request)->flash('success', 'issue.update.deleted');

            return $this->redirect->toRoute('issues.show', $back);
        }

        if ($request->getMethod() !== 'POST') {
            $values = $update === null
                ? ['noted_on' => LocalTime::today($this->clock, $zone)->format('Y-m-d'), 'status' => $issue->status()->value]
                : IssueForm::updateValues($update, $user->preferences);

            return $this->render($request, $response, $vehicle, $issue, $update, $values, null);
        }

        $data = IssueForm::parseUpdate(
            RequestContext::form($request),
            $user->preferences,
            LocalTime::today($this->clock, $zone),
            $issue,
            $update,
        );
        if ($data instanceof ValidationErrors) {
            return $this->render($request, $response, $vehicle, $issue, $update, IssueRoute::formValues($request), $data, 422);
        }

        $session = RequestContext::session($request);
        if ($update === null) {
            $update = $this->issues->addUpdate($vehicle, $issue, $data, $zone);
            $session->flash('success', 'issue.update.added');
        } else {
            $this->issues->editUpdate($vehicle, $issue, $update, $data, $zone);
            $session->flash('success', 'issue.update.saved');
        }
        $this->warnings->queue($session, $this->issues->updateOdometerWarning($vehicle, $update));

        return $this->redirect->backOr($request, 'issues.show', $back);
    }

    /**
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        Issue $issue,
        ?IssueUpdate $update,
        array $values,
        ?ValidationErrors $errors,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'issues/update_form.twig', [
            'vehicle' => $vehicle,
            'issue' => $issue,
            'update' => $update,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
        ], $status);
    }
}
