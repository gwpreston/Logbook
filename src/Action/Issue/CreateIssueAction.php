<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Service\Issue\IssueForm;
use Logbook\Service\Issue\IssueService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET/POST /vehicles/{id}/issues/new — log an issue (spec.md §7.37).
 */
final readonly class CreateIssueAction
{
    public function __construct(
        private IssueService $issues,
        private IssueFormPage $page,
        private AttachmentUpload $upload,
        private Redirector $redirect,
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
        $zone = $user->preferences->timeZone();
        $today = LocalTime::today($this->clock, $zone);
        $refused = IssueRoute::refuseArchived($request, $vehicle, $this->redirect);
        if ($refused !== null) {
            return $refused;
        }

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, $vehicle, IssueForm::defaults($today));
        }

        $data = IssueForm::parse(RequestContext::form($request), $user->preferences, $today);
        $files = $this->upload->fromRequest($request, owner: AttachmentOwner::Issue);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            return $this->page->render($request, $response, $vehicle, IssueRoute::formValues($request), null, $errors, 422);
        }

        $issue = $this->issues->create($vehicle, $data, $zone, $files);
        $session = RequestContext::session($request);
        $session->flash('success', 'issue.created');
        $this->warnings->queue($session, $this->issues->odometerWarning($vehicle, $issue));

        return $this->redirect->backOr($request, 'issues.show', [
            'id' => (string) $vehicle->id,
            'issue' => (string) $issue->id,
        ]);
    }
}
