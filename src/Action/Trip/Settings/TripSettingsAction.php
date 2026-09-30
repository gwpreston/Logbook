<?php

declare(strict_types=1);

namespace Logbook\Action\Trip\Settings;

use Logbook\Service\Trip\MileageRateService;
use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Service\Trip\TripSettingsForm;
use Logbook\Service\Trip\TripSettingsStore;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\Region;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/trips — Settings → Trips (spec.md §7.22, §7.23): the
 * tax year start and declaration, the user's saved journeys and their
 * mileage rates. GB users get HMRC's rates the first time they come here
 * with none. Routed only while the trips module is on.
 */
final readonly class TripSettingsAction
{
    public function __construct(
        private View $view,
        private TripSettingsStore $settings,
        private SavedJourneyService $journeys,
        private MileageRateService $rates,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $rates = $this->rates->forUser($user);
        $current = $this->settings->for($user);
        $values = TripSettingsForm::values($current);
        $errors = [];
        $status = 200;

        if ($request->getMethod() === 'POST') {
            $parsed = TripSettingsForm::parse(RequestContext::form($request), $user->preferences->locale, $current);
            if (!$parsed instanceof ValidationErrors) {
                $this->settings->save($user, $parsed);
                RequestContext::session($request)->flash('success', 'trip.settings.saved');

                return $this->redirect->toRoute('settings.trips');
            }
            $values = RequestContext::formValues($request);
            $errors = $parsed->all();
            $status = 422;
        }

        return $this->view->render($request, $response, 'settings/trips.twig', [
            'values' => $values,
            'errors' => $errors,
            'journeys' => $this->journeys->forUser($user),
            'rate_sets' => $rates,
            'is_gb' => Region::of($user->preferences->locale) === 'GB',
        ], $status);
    }
}
