<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Issue\Issue;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
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
 * GET/POST /vehicles/{id}/issues/{issue}/fix — *Mark fixed* (spec.md §7.37
 * *Fixing from the issue*): *Log the repair* (a link to the prefilled
 * service form), *Link an existing record* (`how=link`, `record`) or
 * *Fixed without a record* (`how=none`, `fixed_on`, `note`). `Log`.
 */
final readonly class FixIssueAction
{
    public function __construct(
        private IssueService $issues,
        private FeatureToggles $features,
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
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $back = ['id' => (string) $vehicle->id, 'issue' => (string) $issue->id];

        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $response, $vehicle, $issue, ['fixed_on' => $today->format('Y-m-d')], null);
        }

        $form = RequestContext::form($request);
        if (($form['how'] ?? '') === 'link') {
            $record = is_string($form['record'] ?? null) ? (int) $form['record'] : 0;
            $candidates = array_map(static fn ($r): int => $r->id, $this->linkable($vehicle, $issue));
            if (!in_array($record, $candidates, true)) {
                $errors = new ValidationErrors();
                $errors->add('record', 'issue.error.record');

                return $this->render($request, $response, $vehicle, $issue, IssueRoute::formValues($request), $errors, 422);
            }
            $this->issues->fixWith($vehicle, $issue, [$record]);
            RequestContext::session($request)->flash('success', 'issue.fixed');

            return $this->redirect->backOr($request, 'issues.show', $back);
        }

        $data = IssueForm::parseFixedWithout($form, $user->preferences, $today, $issue);
        if ($data instanceof ValidationErrors) {
            return $this->render($request, $response, $vehicle, $issue, IssueRoute::formValues($request), $data, 422);
        }
        $this->issues->fixWithoutRecord($vehicle, $issue, $data->notedOn, $data->note);
        RequestContext::session($request)->flash('success', 'issue.fixed');

        return $this->redirect->backOr($request, 'issues.show', $back);
    }

    /**
     * @return list<\Logbook\Domain\Maintenance\MaintenanceEntry>
     */
    private function linkable(Vehicle $vehicle, Issue $issue): array
    {
        return $this->features->isEnabled(Feature::Maintenance) ? $this->issues->linkableRecords($vehicle, $issue) : [];
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
        return $this->view->render($request, $response, 'issues/fix.twig', [
            'vehicle' => $vehicle,
            'issue' => $issue,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'records' => $this->linkable($vehicle, $issue),
            'maintenance' => $this->features->isEnabled(Feature::Maintenance),
        ], $status);
    }
}
