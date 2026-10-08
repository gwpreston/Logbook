<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The agreement page's forms (spec.md §7.32): mark a payment missed or
 * paid late, add an extra payment, add a settlement quote, and *End*. The
 * rules are shared by the page and the API (Phase 39.2); each checks its
 * input, then records it, or answers why not with the form's messages.
 */
final readonly class FinanceEvents
{
    private const int MONEY_SCALE = 2;
    private const int MONEY_WHOLE_DIGITS = 11;

    public function __construct(private FinanceService $finance)
    {
    }

    /**
     * `kind` (`extra`: `amount`, `paid_on`, `notes`; `missed`: `due_on`;
     * `paid_late`: `due_on` and an optional `paid_on`). A missed mark needs
     * a payment paid on the schedule that day, paid late a missed one.
     *
     * @param array<array-key, mixed> $input
     * @return PaymentEventKind|ValidationErrors what was recorded, or why not
     */
    public function payment(
        User $user,
        Vehicle $vehicle,
        FinanceAgreement $agreement,
        array $input,
    ): PaymentEventKind|ValidationErrors {
        $validator = new Validator($input, $user->preferences->locale);
        $kind = $validator->enum('kind', PaymentEventKind::class, true);
        $today = $this->finance->ownerToday($user, $vehicle);

        if ($kind === PaymentEventKind::Extra) {
            $amount = $validator->decimal('amount', true, self::MONEY_SCALE, '0.01', null, self::MONEY_WHOLE_DIGITS);
            $paidOn = $validator->date('paid_on', true);
            $notes = $validator->string('notes', false, 500);
            if ($paidOn !== null && $paidOn > $today) {
                $validator->addError('paid_on', 'finance.error.extra');
            }
            if (!$validator->errors()->isEmpty() || $amount === null || $paidOn === null) {
                return $validator->errors();
            }
            $this->finance->addExtraPayment($user, $vehicle, $agreement, $amount, $paidOn, $notes);

            return $kind;
        }

        if ($kind !== PaymentEventKind::Missed && $kind !== PaymentEventKind::PaidLate) {
            if ($kind !== null) {
                // A settlement is recorded by End (#299).
                $validator->addError('kind', 'validation.choice');
            }

            return $validator->errors();
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
        if ($dueOn !== null && !$allowed) {
            $validator->addError('due_on', 'finance.error.mark');
        }
        if ($dueOn !== null && $paidOn !== null && ($paidOn < $dueOn || $paidOn > $today)) {
            $validator->addError('paid_on', 'finance.error.mark');
        }
        if (!$validator->errors()->isEmpty() || $dueOn === null) {
            return $validator->errors();
        }
        $this->finance->markPayment($user, $vehicle, $agreement, $kind, $dueOn, $paidOn);

        return $kind;
    }

    /**
     * `quoted_on`, `quote_amount`, `valid_until` (not before the quote) and `quote_notes`.
     *
     * @param array<array-key, mixed> $input
     */
    public function quote(User $user, Vehicle $vehicle, FinanceAgreement $agreement, array $input): ?ValidationErrors
    {
        $validator = new Validator($input, $user->preferences->locale);
        $quotedOn = $validator->date('quoted_on', true);
        $amount = $validator->decimal('quote_amount', true, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS);
        $validUntil = $validator->date('valid_until', true);
        $notes = $validator->string('quote_notes', false, 500);
        if ($quotedOn !== null && $validUntil !== null && $validUntil < $quotedOn) {
            $validator->addError('valid_until', 'finance.error.quote');
        }
        if (!$validator->errors()->isEmpty() || $quotedOn === null || $amount === null || $validUntil === null) {
            return $validator->errors();
        }
        $this->finance->addQuote($user, $vehicle, $agreement, $quotedOn, $amount, $validUntil, $notes);

        return null;
    }

    /**
     * *End*: `outcome` (one of the type's, FinanceService::endOutcomes),
     * `ended_on` (not in the future, not before the start; completed not
     * before the last payment), `settlement` when settled, and
     * `excess_charge` and `damage_charge` when handed back or ended.
     *
     * @param array<array-key, mixed> $input
     * @return AgreementStatus|ValidationErrors how it ended, or why not
     */
    public function end(
        User $user,
        Vehicle $vehicle,
        FinanceAgreement $agreement,
        AgreementView $view,
        array $input,
    ): AgreementStatus|ValidationErrors {
        $outcomes = FinanceService::endOutcomes($agreement->type());
        $validator = new Validator($input, $user->preferences->locale);
        $outcome = AgreementStatus::tryFrom((string) $validator->choice(
            'outcome',
            array_map(static fn (AgreementStatus $s): string => $s->value, $outcomes),
            true,
        ));
        $endedOn = $validator->date('ended_on', true);
        $today = $this->finance->ownerToday($user, $vehicle);
        if ($endedOn !== null && $endedOn > $today) {
            $validator->addError('ended_on', 'finance.end.error_future');
        } elseif ($endedOn !== null && $endedOn < $agreement->data->startedOn) {
            $validator->addError('ended_on', 'finance.end.error_before_start');
        }
        $lastPayment = $view->figures->endsOn;
        if ($outcome === AgreementStatus::Completed && $endedOn !== null && $lastPayment !== null && $endedOn < $lastPayment) {
            $validator->addError('ended_on', 'finance.end.error_completed');
        }
        $settlement = $outcome === AgreementStatus::Settled
            ? $validator->decimal('settlement', true, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS)
            : null;
        $returned = $outcome === AgreementStatus::HandedBack || $outcome === AgreementStatus::Ended;
        $charge = static fn (string $field): ?string => $returned
            ? $validator->decimal($field, false, self::MONEY_SCALE, '0', null, self::MONEY_WHOLE_DIGITS)
            : null;
        $excess = $charge('excess_charge');
        $damage = $charge('damage_charge');

        if (!$validator->errors()->isEmpty() || $outcome === null || $endedOn === null) {
            return $validator->errors();
        }
        $this->finance->end($user, $vehicle, $agreement, new EndAgreement($outcome, $endedOn, $settlement, $excess, $damage));

        return $outcome;
    }
}
