<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Issue\IssueUpdate;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Issue\IssueService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/issues/{issue} — the issue page (spec.md §7.37): what
 * was noticed, the files, the look-again point, the timeline, what fixed
 * it, and the actions the viewer may take.
 */
final readonly class ShowIssueAction
{
    public function __construct(
        private IssueService $issues,
        private AttachmentService $attachments,
        private VehicleAccess $vehicles,
        private EntryAccess $entries,
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
        $issue = IssueRoute::issue($this->issues, $vehicle, $request, $args);
        $active = !$vehicle->isArchived();
        $updates = $this->issues->updatesOf($issue);
        $changeable = [];
        foreach ($updates as $update) {
            if ($active && !$update->isAutomatic() && $this->entries->canChange($user, $vehicle, $update->createdBy)) {
                $changeable[] = $update->id;
            }
        }

        return $this->view->render($request, $response, 'issues/show.twig', [
            'vehicle' => $vehicle,
            'issue' => $issue,
            'updates' => $updates,
            'changeable_updates' => $changeable,
            'readings' => array_filter(
                array_map(static fn (IssueUpdate $u): ?string => $u->odometerKm, $updates),
                static fn (?string $km): bool => $km !== null,
            ),
            'fixes' => $this->issues->fixesOf($vehicle, $issue),
            'files' => $this->attachments->forOwner($vehicle, AttachmentOwner::Issue, $issue->id),
            'can_change' => $active && $this->entries->canChange($user, $vehicle, $issue->createdBy),
            'can_log' => $active && $this->vehicles->can($user, VehicleAbility::Log, $vehicle),
        ]);
    }
}
