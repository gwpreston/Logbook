<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Domain\Issue\IssueStatus;
use Logbook\Service\Issue\IssueService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /vehicles/{id}/issues/{issue}/reopen — back to open (spec.md §7.37):
 * *It's back* on a fixed issue, *Reopen* from *Look again*, or, with
 * `stop=1`, *Stop watching*. `Log`.
 */
final readonly class ReopenIssueAction
{
    public function __construct(
        private IssueService $issues,
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
        $issue = IssueRoute::issue($this->issues, $vehicle, $request, $args);
        $refused = IssueRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }
        $zone = $user->preferences->timeZone();
        $stop = (RequestContext::form($request)['stop'] ?? '') === '1';

        if ($stop && $issue->status() === IssueStatus::Watching) {
            $this->issues->stopWatching($vehicle, $issue, $zone);
            RequestContext::session($request)->flash('success', 'issue.stopped_watching');
        } else {
            $message = $issue->isFixed() ? 'issue.back' : 'issue.reopened';
            $this->issues->reopen($vehicle, $issue, $zone);
            RequestContext::session($request)->flash('success', $message);
        }

        return $this->redirect->backOr($request, 'issues.show', [
            'id' => (string) $vehicle->id,
            'issue' => (string) $issue->id,
        ]);
    }
}
