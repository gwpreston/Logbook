<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use DateTimeImmutable;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Incident\TotalLoss;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/archive — hide a sold/retired vehicle from active
 * views, keeping its history. A POST without `disposal` archives in one
 * click. A vehicle with a settled write-off gets the confirm page (spec.md
 * §7.29 *Total loss*): *Written off*, with the incident and the settlement
 * as the sale date and price, or *Just archive*.
 */
final readonly class ArchiveVehicleAction
{
    public const string WRITTEN_OFF = 'written_off';
    private const int MONEY_SCALE = 3;
    private const int MONEY_WHOLE_DIGITS = 11;

    public function __construct(
        private VehicleService $vehicles,
        private TotalLoss $totalLoss,
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
        $show = $this->redirect->toRoute('vehicles.show', ['id' => (string) $vehicle->id]);
        if ($vehicle->isArchived()) {
            return $show;
        }
        $candidates = $this->totalLoss->candidates($vehicle);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        if ($request->getMethod() !== 'POST') {
            if ($candidates === []) {
                return $show;
            }
            // *Use its settlement* without JS: the chosen incident's date and price.
            $chosen = $request->getQueryParams()['incident_id'] ?? null;
            $incident = $candidates[0];
            foreach ($candidates as $candidate) {
                $incident = (string) $candidate->id === $chosen ? $candidate : $incident;
            }
            $values = ['disposal' => self::WRITTEN_OFF] + self::prefill($incident, $today);

            return $this->render($request, $response, $user, $vehicle, $candidates, $values);
        }

        $input = RequestContext::form($request);
        if (($input['disposal'] ?? '') !== self::WRITTEN_OFF || $candidates === []) {
            $this->vehicles->archive($user, $vehicle);
            RequestContext::session($request)->flash('success', 'vehicle.archived', ['name' => $vehicle->name()]);

            return $show;
        }

        $validator = new Validator($input, $user->preferences->locale);
        $incidentId = $validator->choice(
            'incident_id',
            array_map(static fn (Incident $incident): string => (string) $incident->id, $candidates),
            true,
        );
        $saleDate = $validator->date('sale_date', true);
        $salePrice = $validator->decimal('sale_price', true, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $purchased = $vehicle->data->purchaseDate;
        if ($purchased !== null && $saleDate !== null && $saleDate < $purchased) {
            $validator->addError('sale_date', 'vehicle.sale_before_purchase');
        }
        if (!$validator->errors()->isEmpty() || $incidentId === null || $saleDate === null || $salePrice === null) {
            $values = RequestContext::formValues($request);

            return $this->render($request, $response, $user, $vehicle, $candidates, $values, $validator->errors(), 422);
        }

        $this->vehicles->archiveWrittenOff($user, $vehicle, (int) $incidentId, $saleDate, $salePrice);
        RequestContext::session($request)->flash('success', 'vehicle.archived_written_off', ['name' => $vehicle->name()]);

        return $show;
    }

    /**
     * @return array<string, string>
     */
    private static function prefill(Incident $incident, DateTimeImmutable $today): array
    {
        return [
            'incident_id' => (string) $incident->id,
            'sale_date' => TotalLoss::saleDate($incident, $today)->format('Y-m-d'),
            'sale_price' => TotalLoss::salePrice($incident),
        ];
    }

    /**
     * @param list<Incident> $candidates
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        Vehicle $vehicle,
        array $candidates,
        array $values,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $prefills = [];
        foreach ($candidates as $incident) {
            $prefills[$incident->id] = self::prefill($incident, $today);
        }

        return $this->view->render($request, $response, 'vehicles/archive.twig', [
            'vehicle' => $vehicle,
            'candidates' => $candidates,
            'prefills' => $prefills,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
        ], $status);
    }
}
