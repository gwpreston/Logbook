<?php

declare(strict_types=1);

namespace Logbook\Action\Trip\Settings;

use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Service\Trip\MileageRateService;
use Logbook\Service\Trip\RateSetForm;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Money\Currency;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /settings/trips/rates/new and /{set}/edit — a mileage rate set
 * (spec.md §7.23). The add form starts from the set in effect today.
 * Editing a set changes the value of every trip it applies to.
 */
final readonly class RateSetFormAction
{
    public function __construct(
        private MileageRateService $rates,
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
        $user = RequestContext::requireUser($request);
        $set = null;
        if (isset($args['set'])) {
            $set = $this->rates->find($user, (int) $args['set']) ?? throw new HttpNotFoundException($request);
        }
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        if ($request->getMethod() !== 'POST') {
            $values = $set === null
                ? RateSetForm::defaults($this->rates->inEffect($user, $today), $user->preferences, $today)
                : RateSetForm::values($set->data);

            return $this->render($request, $response, $values, $set, []);
        }

        $data = RateSetForm::parse(RequestContext::form($request), $user->preferences);
        if (!$data instanceof ValidationErrors && $this->rates->isDateTaken($user, $data->effectiveFrom, $set)) {
            $data = new ValidationErrors();
            $data->add('effective_from', 'trip.rates.error.date_taken');
        }
        if ($data instanceof ValidationErrors) {
            return $this->render($request, $response, RequestContext::formValues($request), $set, $data->all(), 422);
        }

        if ($set === null) {
            $this->rates->create($user, $data);
        } else {
            $this->rates->update($user, $set, $data);
        }
        RequestContext::session($request)->flash('success', 'trip.rates.saved');

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
        ?MileageRateSet $set,
        array $errors,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'settings/trip_rates.twig', [
            'set' => $set,
            'values' => $values,
            'errors' => $errors,
            'currencies' => Currency::SUPPORTED,
        ], $status);
    }
}
