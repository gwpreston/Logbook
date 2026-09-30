<?php

declare(strict_types=1);

namespace Logbook\Action\Compliance;

use Logbook\Action\EntryGuard;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET|POST /vehicles/{id}/documents/{document}/delete — confirm (works
 * without JS), then delete a document and its attached files.
 */
final readonly class DeleteComplianceDocumentAction
{
    public function __construct(
        private ComplianceService $compliance,
        private TranslatorInterface $translator,
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
        $document = ComplianceRoute::document($this->compliance, $vehicle, $request, $args);
        $this->guard->allowChange($request, $vehicle, $document->createdBy);
        $data = $document->data;
        $description = ['name' => $data->title ?? $this->translator->trans('compliance.type.' . $data->type->value)];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'entries/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'compliance.delete_title',
                'body' => 'compliance.delete_body',
                'params' => $description,
                'action' => ['compliance.delete', ['id' => $vehicle->id, 'document' => $document->id]],
                'cancel' => ['compliance.index', ['id' => $vehicle->id]],
            ]);
        }

        $this->compliance->delete($vehicle, $document);
        RequestContext::session($request)->flash('success', 'compliance.deleted', $description);

        return $this->redirect->toRoute('compliance.index', ['id' => (string) $vehicle->id]);
    }
}
