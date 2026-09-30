<?php

declare(strict_types=1);

namespace Logbook\Action\Expense;

use Logbook\Action\EntryGuard;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/expenses/{entry}/delete — confirm (works without
 * JS), then delete an ad-hoc expense.
 */
final readonly class DeleteExpenseAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ExpenseService $expenses,
        private DisplayFormatter $formatter,
        private View $view,
        private Redirector $redirect,
        private EntryGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $entry = ExpenseRoute::entry($this->expenses, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $entry->createdBy);
        $user = RequestContext::requireUser($request);
        $description = [
            'date' => $this->formatter->date($entry->data->spentOn),
            'amount' => $this->formatter->money($entry->data->amount, $this->vehicles->currencyFor($user, $vehicle)),
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'expense.delete_title',
                'body' => 'expense.delete_body',
                'params' => $description,
                'action' => ['expenses.delete', ['id' => $vehicle->id, 'entry' => $entry->id]],
                'cancel' => ['expenses.index', ['id' => $vehicle->id]],
            ]);
        }

        $this->expenses->delete($vehicle, $entry);
        RequestContext::session($request)->flash('success', 'expense.deleted', $description);

        return $this->redirect->toRoute('expenses.index', ['id' => (string) $vehicle->id]);
    }
}
