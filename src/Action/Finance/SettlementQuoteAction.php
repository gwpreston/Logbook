<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Finance\FinanceEvents;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /vehicles/{id}/finance/{agreement}/quotes — the lender's settlement
 * figure (spec.md §7.32 *Settlement*): it replaces the estimate while
 * valid. POST …/quotes/{quote}/delete removes one.
 */
final readonly class SettlementQuoteAction
{
    public function __construct(
        private FinanceService $finance,
        private FinanceEvents $events,
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
        if (!$agreement->type()->isCredit() || $vehicle->isArchived()) {
            throw new HttpNotFoundException($request);
        }
        $back = $this->redirect->toRoute('finance.show', ['id' => (string) $vehicle->id, 'agreement' => (string) $agreement->id]);
        $session = RequestContext::session($request);

        if (isset($args['quote'])) {
            $this->finance->deleteQuote($user, $vehicle, $agreement, (int) $args['quote']);
            $session->flash('success', 'finance.quote_removed');

            return $back;
        }

        if ($this->events->quote($user, $vehicle, $agreement, RequestContext::form($request)) !== null) {
            $session->flash('error', 'finance.error.quote');

            return $back;
        }
        $session->flash('success', 'finance.quote_added');

        return $back;
    }
}
