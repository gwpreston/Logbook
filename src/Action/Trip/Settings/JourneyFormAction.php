<?php

declare(strict_types=1);

namespace Logbook\Action\Trip\Settings;

use Logbook\Domain\Trip\SavedJourney;
use Logbook\Service\Trip\SavedJourneyForm;
use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/trips/journeys/new and /{journey}/edit — add or edit
 * (rename) a saved journey (spec.md §7.22).
 */
final readonly class JourneyFormAction
{
    public function __construct(
        private SavedJourneyService $journeys,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $journey = null;
        if (isset($args['journey'])) {
            $journey = $this->journeys->find($user, (int) $args['journey']) ?? throw new HttpNotFoundException($request);
        }

        if ($request->getMethod() !== 'POST') {
            $values = $journey === null
                ? ['is_business_default' => '1']
                : SavedJourneyForm::values($journey, $user->preferences);

            return $this->render($request, $response, $values, $journey, []);
        }

        $data = SavedJourneyForm::parse(RequestContext::form($request), $user->preferences);
        if ($data instanceof ValidationErrors) {
            return $this->render($request, $response, RequestContext::formValues($request), $journey, $data->all(), 422);
        }

        if ($journey === null) {
            $this->journeys->create($user, $data);
        } else {
            $this->journeys->update($user, $journey, $data);
        }
        RequestContext::session($request)->flash('success', 'trip.journeys.saved');

        return $this->redirect->backOr($request, 'settings.trips');
    }

    /**
     * @param array<string, string> $values
     * @param array<string, mixed> $errors
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?SavedJourney $journey,
        array $errors,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'settings/trip_journey.twig', [
            'journey' => $journey,
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }
}
