<?php

declare(strict_types=1);

namespace Logbook\Action\Expense;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/expenses/new — log an ad-hoc expense (an amount
 * of 0 is fine).
 */
final readonly class CreateExpenseAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ExpenseService $expenses,
        private ExpenseFormPage $page,
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
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            $defaults = ExpenseEntryForm::defaults(LocalTime::today($this->clock, $user->preferences->timeZone()));

            return $this->page->render($request, $response, $vehicle, $currency, $defaults);
        }

        $data = ExpenseEntryForm::parse(RequestContext::form($request), $user->preferences);
        if ($data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, null, $data, 422);
        }

        $this->expenses->create($vehicle, $data);
        RequestContext::session($request)->flash('success', 'expense.created');

        return $this->redirect->toRoute('expenses.index', ['id' => (string) $vehicle->id]);
    }
}
