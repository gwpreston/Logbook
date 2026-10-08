<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Service\Finance\FinanceEvents;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /vehicles/{id}/finance/{agreement}/payments — record an exception
 * to the schedule or a payment outside it (spec.md §7.32 *Schedule*):
 * `kind` = `missed` or `paid_late` (with `due_on`, and for a late payment
 * an optional `paid_on`), or `extra` (`amount`, `paid_on`, `notes`). A
 * mark only applies to a payment of the schedule that is already due.
 */
final readonly class FinancePaymentAction
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
        if (!FinanceService::isOpen($agreement) || $vehicle->isArchived()) {
            throw new HttpNotFoundException($request);
        }
        $back = $this->redirect->toRoute('finance.show', ['id' => (string) $vehicle->id, 'agreement' => (string) $agreement->id]);
        $session = RequestContext::session($request);
        $input = RequestContext::form($request);
        $done = $this->events->payment($user, $vehicle, $agreement, $input);
        if ($done instanceof ValidationErrors) {
            if ($done->has('kind')) {
                throw new HttpNotFoundException($request);
            }
            $extra = ($input['kind'] ?? '') === PaymentEventKind::Extra->value;
            $session->flash('error', $extra ? 'finance.error.extra' : 'finance.error.mark');

            return $back;
        }
        $session->flash('success', match ($done) {
            PaymentEventKind::Extra => 'finance.extra_added',
            PaymentEventKind::Missed => 'finance.marked_missed',
            default => 'finance.marked_paid_late',
        });

        return $back;
    }
}
