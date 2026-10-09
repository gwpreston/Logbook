<?php

declare(strict_types=1);

namespace Logbook\Action\MotHistory;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\MotHistory\MotDefect;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\User\User;
use Logbook\Repository\MotTestRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotReview;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /vehicles/{id}/mot-history/review — the review card (spec.md
 * §7.38 *Review card*), `Log`: passed tests as `inspection` documents
 * (`compliance` is on with the provider), DVSA's first MOT due date
 * (`Manage`, as the vehicle form), and defects as issues (`issues` on).
 *
 * POST `do`: `document` / `documents` (with `test`), `first_due`, `issue`
 * / `not_now` (with `defect`), `issues` (with `test`, or every test), and
 * `done` (with `test`).
 */
final readonly class MotReviewAction
{
    public function __construct(
        private MotHistoryConfig $config,
        private MotTestRepository $tests,
        private MotReview $review,
        private IssueService $issues,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->config->enabled()) {
            throw new HttpNotFoundException($request);
        }
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $issuesOn = $this->features->isEnabled(Feature::Issues) && !$vehicle->isArchived();
        $canManage = $this->access->can($user, VehicleAbility::Manage, $vehicle);

        if ($request->getMethod() === 'POST') {
            $this->act($request, $user, $vehicle, $issuesOn, $canManage);
            $card = $this->review->card($vehicle, $issuesOn);

            return $card->pending()
                ? $this->redirect->toRoute('mot_history.review', ['id' => (string) $vehicle->id])
                : $this->redirect->toRoute('mot_history.show', ['id' => (string) $vehicle->id]);
        }

        $card = $this->review->card($vehicle, $issuesOn);
        $titles = [];
        foreach ($card->tests as $test) {
            foreach (
                [...$test->notSeenAgain, ...array_filter(array_map(
                    static fn ($d): ?int => $d->defect->issueId,
                    $test->defects,
                ))] as $issueId
            ) {
                $issue = $this->issues->find($vehicle, $issueId);
                if ($issue !== null) {
                    $titles[$issueId] = $issue->data->title;
                }
            }
        }

        return $this->view->render($request, $response, 'mot_history/review.twig', [
            'vehicle' => $vehicle,
            'provider' => $this->config->provider(),
            'card' => $card,
            'issues_on' => $issuesOn,
            'can_manage' => $canManage,
            'issue_titles' => $titles,
        ]);
    }

    private function act(ServerRequestInterface $request, User $user, Vehicle $vehicle, bool $issuesOn, bool $canManage): void
    {
        $form = RequestContext::form($request);
        $session = RequestContext::session($request);
        $zone = $user->preferences->timeZone();
        $do = is_string($form['do'] ?? null) ? $form['do'] : '';
        $test = $this->test($vehicle, $form['test'] ?? null);
        [$defectTest, $defect] = $this->defect($vehicle, $form['defect'] ?? null);

        switch ($do) {
            case 'document':
                if ($test !== null && $this->review->addDocument($vehicle, $test)) {
                    $session->flash('success', 'mot_history.review.documents_added', ['count' => 1]);
                }
                break;
            case 'documents':
                $session->flash('success', 'mot_history.review.documents_added', [
                    'count' => $this->review->addAllDocuments($vehicle),
                ]);
                break;
            case 'first_due':
                if ($canManage && $this->review->useFirstDue($user, $vehicle)) {
                    $session->flash('success', 'mot_history.review.first_due_used');
                }
                break;
            case 'issue':
                if (
                    $issuesOn && $defect !== null && $defectTest !== null
                    && $this->review->addIssue($vehicle, $defectTest, $defect)
                ) {
                    $session->flash('success', 'mot_history.review.issues_added', ['count' => 1]);
                }
                break;
            case 'issues':
                if ($issuesOn) {
                    $session->flash('success', 'mot_history.review.issues_added', [
                        'count' => $this->review->addAllIssues($vehicle, $test),
                    ]);
                }
                break;
            case 'not_now':
                if ($defect !== null && $defectTest !== null) {
                    $this->review->notNow($vehicle, $defectTest, $defect);
                }
                break;
            case 'done':
                if ($test !== null) {
                    $this->review->done($test);
                }
                break;
        }
    }

    private function test(Vehicle $vehicle, mixed $id): ?MotTest
    {
        return is_string($id) && ctype_digit($id) ? $this->tests->find($vehicle->id, (int) $id) : null;
    }

    /**
     * @return array{0: MotTest|null, 1: MotDefect|null}
     */
    private function defect(Vehicle $vehicle, mixed $id): array
    {
        if (!is_string($id) || !ctype_digit($id)) {
            return [null, null];
        }
        foreach ($this->tests->listForVehicle($vehicle->id) as $test) {
            foreach ($test->defects as $defect) {
                if ($defect->id === (int) $id) {
                    return [$test, $defect];
                }
            }
        }

        return [null, null];
    }
}
