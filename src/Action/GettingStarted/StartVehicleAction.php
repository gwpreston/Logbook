<?php

declare(strict_types=1);

namespace Logbook\Action\GettingStarted;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\GettingStarted\StartStep;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\GettingStarted\GettingStartedProgress;
use Logbook\Service\GettingStarted\GettingStartedService;
use Logbook\Service\MotHistory\VehicleLookup;
use Logbook\Service\Vehicle\VehicleForm;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET|POST /vehicles/start — *Set up your first vehicle* (spec.md §7.9): the
 * vehicle, its mileage now, its key dates, its servicing and what is next.
 * One page, each step a plain form that works without JS; every step can be
 * skipped, and the flow resumes at the first step not yet saved or skipped.
 * `?step=` reopens a step that was skipped. The vehicle and its progress are
 * the user's own: the vehicle is found from the saved progress, never from
 * the form.
 */
final readonly class StartVehicleAction
{
    public function __construct(
        private GettingStartedService $start,
        private VehicleService $vehicles,
        private VehicleLookup $lookup,
        private FeatureToggles $features,
        private View $view,
        private Redirector $redirect,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $progress = $this->start->progress($user);
        $vehicle = $this->start->vehicle($user, $progress);

        if ($request->getMethod() !== 'POST') {
            $query = $request->getQueryParams()['step'] ?? null;
            $asked = is_string($query) ? StartStep::tryFrom($query) : null;
            $step = $this->chosen($asked, $progress, $vehicle);

            return $this->page($request, $response, $step, $progress, $vehicle, $this->defaults($user, $step, $progress));
        }

        $input = RequestContext::formValues($request);
        $step = $this->chosen(StartStep::tryFrom($input['step'] ?? ''), $progress, $vehicle);
        if ($step === StartStep::Vehicle && $vehicle === null && ($input['skip'] ?? null) !== null) {
            $this->start->dismiss($user);

            return $this->redirect->toRoute('garage');
        }
        if ($vehicle === null || $step === StartStep::Vehicle) {
            return $this->saveVehicle($request, $response, $input, $progress);
        }
        if (($input['skip'] ?? null) !== null) {
            $this->start->skip($user, $step);

            return $this->redirect->toRoute('vehicles.start');
        }

        $result = match ($step) {
            StartStep::Mileage => $this->start->saveMileage($user, $vehicle, $input),
            StartStep::Dates => $this->start->saveDates($user, $vehicle, $input),
            StartStep::Servicing => $this->start->saveServicing($user, $vehicle, $input),
            default => true,
        };
        if ($result instanceof ValidationErrors) {
            return $this->page($request, $response, $step, $progress, $vehicle, $input, $result, 422);
        }
        return $this->redirect->toRoute('vehicles.start');
    }

    /**
     * The step to show: the one asked for when it is on offer, not saved and the vehicle exists, else the next.
     */
    private function chosen(?StartStep $asked, GettingStartedProgress $progress, ?Vehicle $vehicle): StartStep
    {
        $next = $this->start->next($progress, $vehicle);
        $offered = $asked !== null && in_array($asked, $this->start->steps(), true);
        if (!$offered || $vehicle === null || $asked === StartStep::Vehicle) {
            return $next;
        }

        return $progress->isDone($asked) ? $next : $asked;
    }

    /**
     * @param array<string, string> $input
     */
    private function saveVehicle(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $input,
        GettingStartedProgress $progress,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        $values = $input;

        // *Look up* (spec.md §7.38): DVSA's details for the blank fields; nothing is saved.
        if (($input['lookup'] ?? null) === '1') {
            unset($values['lookup']);
            $result = $this->lookup->lookUp($values, $user->id);
            $values = $result['fields'] + $values;
            if (isset($result['mot_expires_on'])) {
                $values['mot_expires_on'] = $result['mot_expires_on'];
            }

            return $this->page($request, $response, StartStep::Vehicle, $progress, null, $values, null, 200, [
                'message' => $this->translator->trans($result['message'], $result['params']),
            ]);
        }

        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        // A first MOT date only when DVSA gave one: nothing is suggested here (the vehicle form's prompt asks later).
        $new = VehicleForm::parseNew($input, $user->preferences, $today, ($input['first_inspection_due_on'] ?? '') !== '');
        if ($new instanceof ValidationErrors) {
            return $this->page($request, $response, StartStep::Vehicle, $progress, null, $values, $new, 422);
        }

        $vehicle = $this->vehicles->create($user, $new->data);
        $mot = $input['mot_expires_on'] ?? '';
        $this->start->vehicleMade($user, $vehicle, preg_match('/^\d{4}-\d{2}-\d{2}$/', $mot) === 1 ? $mot : null);
        RequestContext::session($request)->flash('success', 'vehicle.created', ['name' => $vehicle->name()]);

        return $this->redirect->toRoute('vehicles.start');
    }

    /**
     * @return array<string, string>
     */
    private function defaults(User $user, StartStep $step, GettingStartedProgress $progress): array
    {
        return match ($step) {
            StartStep::Vehicle => ['type' => VehicleType::Car->value, 'fuel_type' => FuelType::Petrol->value],
            StartStep::Dates => ['mot_due_on' => $progress->motExpiresOn ?? ''],
            StartStep::Servicing => $this->start->servicingDefaults($user),
            default => [],
        };
    }

    /**
     * @param array<string, string> $values
     * @param array{message: string}|null $lookup
     */
    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        StartStep $step,
        GettingStartedProgress $progress,
        ?Vehicle $vehicle,
        array $values,
        ?ValidationErrors $errors = null,
        int $status = 200,
        ?array $lookup = null,
    ): ResponseInterface {
        $user = RequestContext::requireUser($request);
        if ($step === StartStep::Done && !$progress->isDone(StartStep::Done)) {
            $this->start->finish($user);
        }

        $steps = $this->start->steps();

        return $this->view->render($request, $response, 'getting_started/step.twig', [
            'step' => $step,
            'steps' => $steps,
            'position' => ((int) array_search($step, $steps, true)) + 1,
            'progress' => $progress,
            'vehicle' => $vehicle,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'lookup' => $lookup,
            'with_lookup' => $this->lookup->available(),
            'types' => VehicleType::cases(),
            'fuel_types' => FuelType::cases(),
            'fuel_on' => $this->features->isEnabled(Feature::Fuel),
            'max_date' => LocalTime::today($this->clock, $user->preferences->timeZone())->format('Y-m-d'),
        ], $status);
    }
}
