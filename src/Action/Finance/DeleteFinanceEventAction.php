<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Finance\FinanceAgreementNotFound;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /vehicles/{id}/finance/{agreement}/events/{event}/delete — undo a
 * mark (a missed mark takes its paid-late one with it) or remove an extra
 * payment.
 */
final readonly class DeleteFinanceEventAction
{
    public function __construct(
        private FinanceService $finance,
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
        try {
            $this->finance->deleteEvent($user, $vehicle, $agreement, (int) ($args['event'] ?? 0));
        } catch (FinanceAgreementNotFound) {
            throw new HttpNotFoundException($request);
        }
        RequestContext::session($request)->flash('success', 'finance.event_removed');

        return $this->redirect->toRoute('finance.show', ['id' => (string) $vehicle->id, 'agreement' => (string) $agreement->id]);
    }
}
