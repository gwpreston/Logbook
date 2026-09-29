<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Tyre\TyreChangeForm;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /vehicles/{id}/tyres/{kind} — record a tyre change: tyres already
 * on the vehicle, fit, swap set, rotate, repair or remove (spec.md §7.17).
 * One route and form page for the six kinds; each kind parses its own
 * fields and the service checks them against the tyres as they are.
 * `?set={id}` on the swap form fits that set.
 */
final readonly class TyreChangeFormAction
{
    public function __construct(
        private VehicleService $vehicles,
        private TyreChangeService $changes,
        private TyreFormPage $page,
        private OdometerService $odometer,
        private OdometerWarningFlash $warnings,
        private Redirector $redirect,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $kind = TyreChangeKind::tryFrom($args['kind'] ?? '') ?? throw new HttpNotFoundException($request);
        $user = RequestContext::requireUser($request);
        $preferences = $user->preferences;
        $today = LocalTime::today($this->clock, $preferences->timeZone());
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $context = $this->page->context($vehicle, $today, $today);
            $values = TyreChangeForm::defaults($today, $this->odometer->history($vehicle)->latest(), $preferences);
            $set = $request->getQueryParams()['set'] ?? null;
            $fitSet = is_string($set) && ctype_digit($set) ? (int) $set : null;
            $values = $this->page->defaults($vehicle, $kind, $context, $values, $fitSet);

            return $this->page->render($request, $response, $vehicle, $kind, $context, $currency, $values);
        }

        $input = RequestContext::form($request);
        $on = LocalTime::parseDate(is_string($input['done_on'] ?? null) ? $input['done_on'] : '') ?? $today;
        $context = $this->page->context($vehicle, $today, $on);
        $parsed = TyreChangeForm::parse($kind, $input, $preferences, $context);
        $values = RequestContext::formValues($request);
        if ($parsed instanceof ValidationErrors) {
            return $this->page->render($request, $response, $vehicle, $kind, $context, $currency, $values, null, $parsed, 422);
        }

        try {
            $change = $this->changes->record($vehicle, $parsed, $preferences->timeZone(), $preferences->locale);
        } catch (TyreChangeRefused $refused) {
            $errors = $this->page->errors($refused);

            return $this->page->render($request, $response, $vehicle, $kind, $context, $currency, $values, null, $errors, 422);
        }

        $session = RequestContext::session($request);
        $session->flash('success', 'tyre.saved.' . $kind->value);
        $this->warnings->queue($session, $change->data->maintenanceEntryId === null
            ? $this->odometer->warningForEntry($vehicle, OdometerSource::Tyre, $change->id)
            : $this->odometer->warningForEntry($vehicle, OdometerSource::Maintenance, $change->data->maintenanceEntryId));

        return $this->redirect->backOr($request, 'tyres.index', ['id' => (string) $vehicle->id]);
    }
}
