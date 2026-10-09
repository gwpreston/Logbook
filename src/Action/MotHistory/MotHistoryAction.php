<?php

declare(strict_types=1);

namespace Logbook\Action\MotHistory;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\MotHistory\MotTest;
use Logbook\Repository\MotTestRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\MotHistory\MotHistoryConfig;
use Logbook\Service\MotHistory\MotReview;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /vehicles/{id}/mot-history — the vehicle's MOT history (spec.md
 * §7.38 *Pages*): the recall state, each test newest first with its
 * defects and what was made from it, and *Fetch*, *Refresh* and *Stop and
 * remove* for the owner. A 404 while the provider is off.
 */
final readonly class MotHistoryAction
{
    public function __construct(
        private MotHistoryConfig $config,
        private MotTestRepository $tests,
        private MotReview $review,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $provider = $this->config->provider() ?? throw new HttpNotFoundException($request);
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $tests = $this->tests->listForVehicle($vehicle->id);
        $documents = $this->review->documentsFor($vehicle, $tests);
        $issuesOn = $this->features->isEnabled(Feature::Issues);

        return $this->view->render($request, $response, 'mot_history/show.twig', [
            'vehicle' => $vehicle,
            'provider' => $provider,
            'state' => $this->tests->state($vehicle->id),
            'tests' => $tests,
            'documents' => $documents,
            'issues_on' => $issuesOn,
            'can_fetch' => $this->access->can($user, VehicleAbility::Own, $vehicle),
            'can_review' => $this->access->can($user, VehicleAbility::Log, $vehicle),
            'review_pending' => $tests !== []
                && array_filter($tests, static fn (MotTest $t): bool => $t->reviewedAt === null) !== []
                && $this->review->card($vehicle, $issuesOn)->pending(),
            'has_identifier' => trim((string) $vehicle->data->registration) !== '' || trim((string) $vehicle->data->vin) !== '',
        ]);
    }
}
