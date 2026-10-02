<?php

declare(strict_types=1);

namespace Logbook\Action\Finance;

use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\Validator;
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
        $validator = new Validator(RequestContext::form($request), $user->preferences->locale);
        $kind = $validator->enum('kind', PaymentEventKind::class, true);
        $today = $this->finance->ownerToday($user, $vehicle);

        if ($kind === PaymentEventKind::Extra) {
            $amount = $validator->decimal('amount', true, 2, '0.01', null, 11);
            $paidOn = $validator->date('paid_on', true);
            $notes = $validator->string('notes', false, 500);
            if ($amount === null || $paidOn === null || $paidOn > $today) {
                $session->flash('error', 'finance.error.extra');

                return $back;
            }
            $this->finance->addExtraPayment($user, $vehicle, $agreement, $amount, $paidOn, $notes);
            $session->flash('success', 'finance.extra_added');

            return $back;
        }

        if ($kind !== PaymentEventKind::Missed && $kind !== PaymentEventKind::PaidLate) {
            throw new HttpNotFoundException($request);
        }
        $dueOn = $validator->date('due_on', true);
        $paidOn = $kind === PaymentEventKind::PaidLate ? $validator->date('paid_on') : null;
        $payment = null;
        foreach ($this->finance->view($user, $vehicle, $agreement)->figures->schedule->payments as $candidate) {
            if ($dueOn !== null && $candidate->dueOn == $dueOn) {
                $payment = $candidate;
            }
        }
        $allowed = $payment !== null && match ($kind) {
            PaymentEventKind::Missed => $payment->status->value === 'paid',
            PaymentEventKind::PaidLate => $payment->status->value === 'missed',
        };
        if (!$allowed || $dueOn === null || ($paidOn !== null && ($paidOn < $dueOn || $paidOn > $today))) {
            $session->flash('error', 'finance.error.mark');

            return $back;
        }
        $this->finance->markPayment($user, $vehicle, $agreement, $kind, $dueOn, $paidOn);
        $session->flash('success', $kind === PaymentEventKind::Missed ? 'finance.marked_missed' : 'finance.marked_paid_late');

        return $back;
    }
}
