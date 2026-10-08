<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use DateTimeImmutable;
use Logbook\Domain\Incident\Incident;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Incident\TotalLoss;
use Logbook\Service\Vehicle\VehicleArchiving;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/archive — hide a sold/retired vehicle from active
 * views, keeping its history. A POST without `disposal` archives in one
 * click. A vehicle with a settled write-off or a finance agreement gets the
 * confirm page (spec.md §7.29 *Total loss*, §7.32 *Archive page*):
 *
 * - *Written off*, with the incident and the settlement as the sale date
 *   and price;
 * - *Sold* (while an agreement is active, not for a lease), with the sale
 *   date and price; an active HP or PCP warns and offers *Settled from the
 *   sale*, which ends it as settled early on the sale date;
 * - *Returned to the lender* (PCP: the optional final payment as the sale
 *   price) or *Returned to the lessor* (lease: no sale price), ending an
 *   active agreement as handed back or lease ended on the sale date;
 * - *Just archive*, which leaves an active agreement as it is.
 */
final readonly class ArchiveVehicleAction
{
    public function __construct(
        private VehicleArchiving $archiving,
        private VehicleService $vehicles,
        private FinanceService $finance,
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
        $options = $this->archiving->options($user, $vehicle);
        ['candidates' => $candidates, 'agreement' => $agreement, 'choices' => $choices] = $options;
        $finance = $agreement === null ? null : $this->finance->view($user, $vehicle, $agreement);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        if ($request->getMethod() !== 'POST') {
            if ($candidates === [] && $finance === null) {
                return $show;
            }
            $query = $request->getQueryParams();
            $asked = is_string($query['disposal'] ?? null) ? $query['disposal'] : '';
            $disposal = in_array($asked, $choices, true) && $asked !== '' ? $asked : $choices[0];
            // *Use its settlement* without JS: the chosen incident's date and price.
            $values = ['disposal' => $disposal] + $this->prefill($user, $vehicle, $candidates, $finance, $today, $query);

            return $this->render($request, $response, $user, $vehicle, $candidates, $finance, $choices, $values);
        }

        $done = $this->archiving->archive($user, $vehicle, RequestContext::form($request));
        if ($done instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->render($request, $response, $user, $vehicle, $candidates, $finance, $choices, $values, $done, 422);
        }
        RequestContext::session($request)->flash('success', $done, ['name' => $vehicle->name()]);

        return $show;
    }

    /**
     * @param list<Incident> $candidates
     * @param array<mixed> $query
     * @return array<string, string>
     */
    private function prefill(
        User $user,
        Vehicle $vehicle,
        array $candidates,
        ?AgreementView $finance,
        DateTimeImmutable $today,
        array $query,
    ): array {
        $values = [];
        if ($finance !== null) {
            $agreement = $finance->agreement;
            $values['sale_date'] = ($agreement->endedOn ?? $today)->format('Y-m-d');
            $defaults = $this->finance->endDefaults($user, $vehicle, $finance);
            $values['settlement'] = $defaults['settlement'] ?? '';
            $values['settle_from_sale'] = '1';
        }
        if ($candidates !== []) {
            $chosen = $query['incident_id'] ?? null;
            $incident = $candidates[0];
            foreach ($candidates as $candidate) {
                $incident = (string) $candidate->id === $chosen ? $candidate : $incident;
            }
            $values = self::incidentPrefill($incident, $today) + $values;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function incidentPrefill(Incident $incident, DateTimeImmutable $today): array
    {
        return [
            'incident_id' => (string) $incident->id,
            'sale_date' => TotalLoss::saleDate($incident, $today)->format('Y-m-d'),
            'sale_price' => TotalLoss::salePrice($incident),
        ];
    }

    /**
     * @param list<Incident> $candidates
     * @param list<string> $choices
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        User $user,
        Vehicle $vehicle,
        array $candidates,
        ?AgreementView $finance,
        array $choices,
        array $values,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $prefills = [];
        foreach ($candidates as $incident) {
            $prefills[$incident->id] = self::incidentPrefill($incident, $today);
        }

        return $this->view->render($request, $response, 'vehicles/archive.twig', [
            'vehicle' => $vehicle,
            'candidates' => $candidates,
            'prefills' => $prefills,
            'finance' => $finance,
            'choices' => $choices,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
        ], $status);
    }
}
