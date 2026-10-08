<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Vehicle\Disposal;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\FinanceEvents;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET|POST /vehicles/{id}/finance/{agreement}/end — *End agreement*
 * (spec.md §7.32 *Ending*): settled early (with the settlement amount),
 * completed, handed back or lease ended, and its date. Handing back and
 * ending a lease take the excess mileage and damage charges, logged as
 * expenses (#127), then lead someone who may archive the vehicle to the
 * archive page with *Returned to the lender* or *the lessor* chosen.
 */
final readonly class EndFinanceAction
{
    public function __construct(
        private FinanceService $finance,
        private FinanceEvents $events,
        private VehicleAccess $access,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $agreement = FinanceRoute::agreement($this->finance, $user, $vehicle, $request, $args);
        if (!FinanceService::isOpen($agreement) || $vehicle->isArchived()) {
            throw new HttpNotFoundException($request);
        }
        $view = $this->finance->view($user, $vehicle, $agreement);
        $outcomes = FinanceService::endOutcomes($agreement->type());
        $defaults = $this->finance->endDefaults($user, $vehicle, $view);

        if ($request->getMethod() !== 'POST') {
            return $this->render($request, $response, $vehicle, $view, [
                'outcome' => $outcomes[0]->value,
                'ended_on' => $defaults['ended_on'],
                'settlement' => $defaults['settlement'] ?? '',
                'excess_charge' => $defaults['excess_charge'] ?? '',
                'damage_charge' => '',
            ]);
        }

        $outcome = $this->events->end($user, $vehicle, $agreement, $view, RequestContext::form($request));
        if ($outcome instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->render($request, $response, $vehicle, $view, $values, $outcome, 422);
        }
        RequestContext::session($request)->flash('success', 'finance.end.done.' . $outcome->value);
        $returned = $outcome === AgreementStatus::HandedBack || $outcome === AgreementStatus::Ended;

        if ($returned && $this->access->can($user, VehicleAbility::Own, $vehicle)) {
            $disposal = $agreement->type() === AgreementType::Lease ? Disposal::ReturnedLessor : Disposal::ReturnedLender;

            $archive = ['id' => (string) $vehicle->id];

            return $this->redirect->toRoute('vehicles.archive', $archive, ['disposal' => $disposal->value]);
        }

        return $this->redirect->toRoute('finance.show', ['id' => (string) $vehicle->id, 'agreement' => (string) $agreement->id]);
    }

    /**
     * @param array<string, string> $values
     */
    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        AgreementView $view,
        array $values,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'finance/end.twig', [
            'vehicle' => $vehicle,
            'finance' => $view,
            'outcomes' => FinanceService::endOutcomes($view->agreement->type()),
            'values' => $values,
            'errors' => $errors?->all() ?? [],
        ], $status);
    }
}
