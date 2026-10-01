<?php

declare(strict_types=1);

namespace Logbook\Action\Expense;

use Logbook\Action\Incident\IncidentPicker;
use Logbook\Action\Attachment\AttachmentUpload;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntry;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the add/edit expense form (shared by both Actions).
 */
final readonly class ExpenseFormPage
{
    public function __construct(
        private View $view,
        private AttachmentUpload $upload,
        private IncidentPicker $incidents,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        string $currency,
        array $values,
        ?ExpenseEntry $entry = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'expenses/form.twig', [
            'vehicle' => $vehicle,
            'entry' => $entry,
            'currency' => $currency,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'categories' => ExpenseCategory::cases(),
        ] + $this->upload->formContext($vehicle, AttachmentOwner::Expense, $entry?->id)
            + $this->incidents->context($vehicle), $status);
    }
}
