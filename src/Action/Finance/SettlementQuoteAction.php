<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\Validator;
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

        $validator = new Validator(RequestContext::form($request), $user->preferences->locale);
        $quotedOn = $validator->date('quoted_on', true);
        $amount = $validator->decimal('quote_amount', true, 2, '0', null, 11);
        $validUntil = $validator->date('valid_until', true);
        $notes = $validator->string('quote_notes', false, 500);
        if ($quotedOn === null || $amount === null || $validUntil === null || $validUntil < $quotedOn) {
            $session->flash('error', 'finance.error.quote');

            return $back;
        }
        $this->finance->addQuote($user, $vehicle, $agreement, $quotedOn, $amount, $validUntil, $notes);
        $session->flash('success', 'finance.quote_added');

        return $back;
    }
}
