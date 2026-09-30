<?php

declare(strict_types=1);

namespace Logbook\Action\Tyre;

use Logbook\Action\EntryGuard;
use Logbook\Action\Odometer\OdometerWarningFlash;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Tyre\TyreChange;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Tyre\TyreChangeForm;
use Logbook\Service\Tyre\TyreChangeRefused;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Service\Tyre\TyreFormContext;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/tyres/changes/{change}/edit — change a tyre
 * change's date, odometer, note and service record link (its lines are
 * fixed). The replay runs; an edit that breaks the sequence is refused with
 * a message naming the problem. Archived vehicles' changes stay editable.
 */
final readonly class EditTyreChangeAction
{
    public function __construct(
        private VehicleService $vehicles,
        private TyreChangeService $changes,
        private TyreFormPage $page,
        private OdometerService $odometer,
        private OdometerWarningFlash $warnings,
        private Redirector $redirect,
        private ClockInterface $clock,
        private EntryGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $change = TyreRoute::change($this->changes, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $change->createdBy);
        $user = RequestContext::requireUser($request);
        $preferences = $user->preferences;
        $today = LocalTime::today($this->clock, $preferences->timeZone());
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $link = $change->data->maintenanceEntryId;

        if ($request->getMethod() !== 'POST') {
            $context = $this->page->context($vehicle, $today, $change->data->doneOn, $link);
            $values = TyreChangeForm::values($change, $preferences);

            return $this->page($request, $response, $vehicle, $change, $context, $currency, $values);
        }

        $input = RequestContext::form($request);
        $on = LocalTime::parseDate(is_string($input['done_on'] ?? null) ? $input['done_on'] : '') ?? $change->data->doneOn;
        $context = $this->page->context($vehicle, $today, $on, $link);
        $data = TyreChangeForm::parseEdit($change, $input, $preferences, $context);
        $values = RequestContext::formValues($request);
        if ($data instanceof ValidationErrors) {
            return $this->page($request, $response, $vehicle, $change, $context, $currency, $values, $data);
        }

        try {
            $updated = $this->changes->update($vehicle, $change, $data, $preferences->timeZone());
        } catch (TyreChangeRefused $refused) {
            $errors = $this->page->errors($refused);

            return $this->page($request, $response, $vehicle, $change, $context, $currency, $values, $errors);
        }

        $session = RequestContext::session($request);
        $session->flash('success', 'tyre.change.updated');
        $this->warnings->queue($session, $updated->data->maintenanceEntryId === null
            ? $this->odometer->warningForEntry($vehicle, OdometerSource::Tyre, $updated->id)
            : $this->odometer->warningForEntry($vehicle, OdometerSource::Maintenance, $updated->data->maintenanceEntryId));

        return $this->redirect->backOr($request, 'tyres.index', ['id' => (string) $vehicle->id]);
    }

    /**
     * @param array<string, string> $values
     */
    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        TyreChange $change,
        TyreFormContext $context,
        string $currency,
        array $values,
        ?ValidationErrors $errors = null,
    ): ResponseInterface {
        $status = $errors === null ? 200 : 422;
        $kind = $change->kind;

        return $this->page->render($request, $response, $vehicle, $kind, $context, $currency, $values, $change, $errors, $status);
    }
}
