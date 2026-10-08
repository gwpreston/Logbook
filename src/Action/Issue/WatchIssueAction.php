<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Domain\Issue\Issue;
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

/**
 * GET/POST /vehicles/{id}/issues/{issue}/watch — *Watch* and *Watch again*
 * (spec.md §7.37), with an optional look-again date and/or mileage. `Log`.
 */
final readonly class WatchIssueAction
{
    public function __construct(
        private IssueService $issues,
        private View $view,
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
        $issue = IssueRoute::issue($this->issues, $vehicle, $request, $args);
        $refused = IssueRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $zone = $user->preferences->timeZone();
        // A fixed issue is reopened with *It's back*, not watched (spec.md §7.37).
        if ($issue->isFixed()) {
            RequestContext::session($request)->flash('error', 'issue.error.fixed');

            return $this->redirect->toRoute('issues.show', ['id' => (string) $vehicle->id, 'issue' => (string) $issue->id]);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $response, $vehicle, $issue, IssueForm::values($issue, $user->preferences), null);
        }

        $point = IssueForm::parseWatch(RequestContext::form($request), $user->preferences, LocalTime::today($this->clock, $zone));
        if ($point instanceof ValidationErrors) {
            return $this->render($request, $response, $vehicle, $issue, IssueRoute::formValues($request), $point, 422);
        }

        $this->issues->watch($vehicle, $issue, $point->on, $point->km, $zone);
        RequestContext::session($request)->flash('success', 'issue.watching');

        return $this->redirect->backOr($request, 'issues.show', [
            'id' => (string) $vehicle->id,
            'issue' => (string) $issue->id,
        ]);
    }

    /**
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        Issue $issue,
        array $values,
        ?ValidationErrors $errors,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'issues/watch.twig', [
            'vehicle' => $vehicle,
            'issue' => $issue,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
        ], $status);
    }
}
