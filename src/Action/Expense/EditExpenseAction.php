<?php

declare(strict_types=1);

namespace Logbook\Action\Expense;

use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /vehicles/{id}/expenses/{entry}/edit — edit an ad-hoc expense in place.
 */
final readonly class EditExpenseAction
{
    public function __construct(
        private VehicleService $vehicles,
        private ExpenseService $expenses,
        private ExpenseFormPage $page,
        private AttachmentUpload $upload,
        private Redirector $redirect,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $entry = ExpenseRoute::entry($this->expenses, $vehicle, $request, $args);
        $user = RequestContext::requireUser($request);
        $currency = $this->vehicles->currencyFor($user, $vehicle);

        if ($request->getMethod() !== 'POST') {
            return $this->page->render($request, $response, $vehicle, $currency, ExpenseEntryForm::values($entry), $entry);
        }

        $data = ExpenseEntryForm::parse(RequestContext::form($request), $user->preferences);
        $files = $this->upload->fromRequest($request);
        $errors = $this->upload->errors($data, $files);
        if ($errors !== null || $data instanceof ValidationErrors) {
            $values = RequestContext::formValues($request);

            return $this->page->render($request, $response, $vehicle, $currency, $values, $entry, $errors, 422);
        }

        $this->expenses->update($vehicle, $entry, $data, $files);
        RequestContext::session($request)->flash('success', 'expense.updated');

        return $this->redirect->backOr($request, 'expenses.index', ['id' => (string) $vehicle->id]);
    }
}
