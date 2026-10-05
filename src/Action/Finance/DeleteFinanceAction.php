<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET/POST /vehicles/{id}/finance/{agreement}/delete — remove an agreement
 * with its events and quotes; its cost lines go with it. To close one that
 * has run its course, end it instead (Phase 29.2).
 */
final readonly class DeleteFinanceAction
{
    public function __construct(
        private FinanceService $finance,
        private TranslatorInterface $translator,
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
        $description = [
            'type' => $this->translator->trans($agreement->type()->labelKey()),
            'lender' => $agreement->data->lender,
        ];

        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'finance/delete.twig', [
                'vehicle' => $vehicle,
                'title' => 'finance.delete_title',
                'body' => 'finance.delete_body',
                'params' => $description,
                'action' => ['finance.delete', ['id' => $vehicle->id, 'agreement' => $agreement->id]],
                'cancel' => ['finance.show', ['id' => $vehicle->id, 'agreement' => $agreement->id]],
            ]);
        }

        $this->finance->delete($user, $vehicle, $agreement);
        RequestContext::session($request)->flash('success', 'finance.deleted', $description);

        return $this->redirect->toRoute('finance.index', ['id' => (string) $vehicle->id]);
    }
}
