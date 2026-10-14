<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use DateTimeImmutable;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Service\Finance\AgreementFigures;
use Logbook\Service\Finance\FinanceFigures;
use Logbook\Service\Finance\InterestStep;
use Logbook\Service\Finance\ScheduledPayment;
use Logbook\Support\AsOf;
use Logbook\Support\AsOfKind;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Working;
use Logbook\Support\WorkingInput;
use Logbook\Support\WorkingLink;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The working of an agreement's figures (spec.md §7.32, §8 *Show the
 * working*): the settlement estimate, equity and interest so far, each
 * written from the values AgreementFigures used, never worked out again.
 */
final readonly class FinanceWorking
{
    /** How many of the latest payments' splits the interest working lists. */
    private const int RECENT_PAYMENTS = 3;

    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
    ) {
    }

    /**
     * Null when there is no settlement (a lease, or an ended agreement).
     */
    public function settlement(FinanceAgreement $agreement, FinanceFigures $figures, DateTimeImmutable $today): ?Working
    {
        $settlement = $figures->settlement;
        if ($settlement === null) {
            return null;
        }
        $link = new WorkingLink('finance.show', ['id' => $agreement->vehicleId, 'agreement' => $agreement->id]);
        $amount = $this->format->money($settlement->amount);
        $quote = $settlement->quote;
        if ($quote !== null) {
            return new Working(
                [
                    new WorkingInput(
                        $this->t('working.finance.quote'),
                        $this->format->money($settlement->amount),
                        $this->quoteAsOf($quote->quotedOn, $today),
                        $link,
                    ),
                    new WorkingInput($this->t('working.finance.valid_until'), $this->format->date($quote->validUntil)),
                ],
                [$this->t('working.finance.settlement_quoted', [
                    'amount' => $amount,
                    'quoted' => $this->format->date($quote->quotedOn),
                    'until' => $this->format->date($quote->validUntil),
                ])],
                [$this->t('working.finance.settlement_quoted_note')],
                $amount,
                $settlement->amount->toDecimal(2),
            );
        }
        $rate = $this->format->percent($settlement->monthlyRate, 2);
        $inputs = [
            new WorkingInput(
                $this->t('working.finance.remaining'),
                $this->t('working.finance.remaining_value', [
                    'count' => $settlement->paymentCount,
                    'total' => $this->format->money($settlement->paymentsTotal),
                ]),
                null,
                $link,
            ),
            new WorkingInput(
                $this->t('working.finance.rate'),
                $this->t('working.finance.rate_value', [
                    'rate' => $rate,
                    'apr' => $this->format->number($agreement->data->apr, 3),
                ]),
            ),
        ];
        $steps = [$this->t('working.finance.settlement_estimate', [
            'count' => $settlement->paymentCount,
            'total' => $this->format->money($settlement->paymentsTotal),
            'rate' => $rate,
            'amount' => $amount,
        ])];
        if ($settlement->extraPayments !== null && !$settlement->extraPayments->isZero()) {
            $extras = $this->format->money($settlement->extraPayments);
            $inputs[] = new WorkingInput($this->t('working.finance.extras'), $extras, null, $link);
            $steps[] = $this->t('working.finance.extras_taken_off', ['extras' => $extras]);
        }

        return new Working(
            $inputs,
            $steps,
            [$this->t('working.finance.settlement_estimate_note')],
            $amount,
            $settlement->amount->toDecimal(2),
        );
    }

    /**
     * Null without equity (no recent valuation, a lease, or no settlement).
     */
    public function equity(FinanceAgreement $agreement, FinanceFigures $figures, DateTimeImmutable $today): ?Working
    {
        $equity = $figures->equity;
        $settlement = $figures->settlement;
        if ($equity === null || $settlement === null) {
            return null;
        }
        $quoted = $settlement->quote;
        $settledWords = $quoted === null
            ? $this->t('working.finance.estimated_today')
            : $this->t('working.finance.quoted_on', ['date' => $this->format->date($quoted->quotedOn)]);
        $value = $this->format->money($equity->valuation);
        $owed = $this->format->money($settlement->amount);
        $result = $this->format->money($equity->amount);

        return new Working(
            [
                new WorkingInput(
                    $this->t('working.finance.value'),
                    $value,
                    $equity->asOf,
                    new WorkingLink('valuations.index', ['id' => $agreement->vehicleId]),
                ),
                new WorkingInput(
                    $this->t('working.finance.settlement'),
                    $owed . ' (' . $settledWords . ')',
                    $quoted === null ? null : $this->quoteAsOf($quoted->quotedOn, $today),
                    new WorkingLink('finance.show', ['id' => $agreement->vehicleId, 'agreement' => $agreement->id]),
                ),
            ],
            [$this->t('working.finance.equity', [
                'value' => $value,
                'date' => $this->format->date($equity->valuedOn),
                'settlement' => $owed,
                'how' => $settledWords,
                'amount' => $result,
            ])],
            [$this->t('working.finance.equity_note', ['months' => AgreementFigures::VALUATION_MONTHS])],
            $result,
            $equity->amount->toDecimal(2),
        );
    }

    /**
     * Null for a lease, or without a figure to measure against.
     */
    public function interestSoFar(FinanceAgreement $agreement, FinanceFigures $figures): ?Working
    {
        $credit = $figures->costOfCredit;
        if ($credit === null || $credit->soFar === null) {
            return null;
        }
        $link = new WorkingLink('finance.show', ['id' => $agreement->vehicleId, 'agreement' => $agreement->id]);
        $result = $this->format->money($credit->soFar);

        if ($credit->exact) {
            if ($credit->paidTotal === null || $credit->base === null) {
                return null;
            }
            $kind = $agreement->data->type->hasCashPrice() ? 'cash_price' : 'amount_of_credit';

            return new Working(
                [
                    new WorkingInput(
                        $this->t('working.finance.paid_total'),
                        $this->format->money($credit->paidTotal),
                        null,
                        $link,
                    ),
                    new WorkingInput($this->t('working.finance.base.' . $kind), $this->format->money($credit->base)),
                ],
                [$this->t('working.finance.interest_exact', [
                    'paid' => $this->format->money($credit->paidTotal),
                    'base' => $this->format->money($credit->base),
                    'amount' => $result,
                ])],
                [$this->t('working.finance.interest_exact_note')],
                $result,
                $credit->soFar->toDecimal(2),
            );
        }
        if ($credit->interestPaid === null || $credit->feesPaid === null || $figures->monthlyRate === null) {
            return null;
        }

        $inputs = [];
        if ($figures->amountOfCredit !== null) {
            $inputs[] = new WorkingInput(
                $this->t('working.finance.credit'),
                $this->format->money($figures->amountOfCredit),
                null,
                $link,
            );
        }
        $inputs[] = new WorkingInput(
            $this->t('working.finance.rate'),
            $this->t('working.finance.rate_value', [
                'rate' => $this->format->percent($figures->monthlyRate, 2),
                'apr' => $this->format->number($agreement->data->apr, 3),
            ]),
        );

        $steps = [$this->t($credit->feesPaid->isZero() ? 'working.finance.interest_sum' : 'working.finance.interest_sum_fees', [
            'count' => $credit->paymentsMade,
            'interest' => $this->format->money($credit->interestPaid),
            'amount' => $result,
            'fees' => $this->format->money($credit->feesPaid),
        ])];
        $made = array_map(static fn (ScheduledPayment $payment): int => $payment->number, $figures->schedule->paid());
        $recent = array_slice(
            array_values(array_filter(
                $figures->interestSteps,
                static fn (InterestStep $step): bool => in_array($step->number, $made, true),
            )),
            -self::RECENT_PAYMENTS,
        );
        foreach ($recent as $step) {
            $steps[] = $this->t('working.finance.interest_payment', [
                'number' => $step->number,
                'date' => $this->format->date($step->dueOn),
                'payment' => $this->format->money($step->payment, $credit->interestPaid->currency),
                'interest' => $this->format->money($step->interest, $credit->interestPaid->currency),
                'capital' => $this->format->money($step->capital(), $credit->interestPaid->currency),
                'balance' => $this->format->money($step->balanceAfter, $credit->interestPaid->currency),
            ]);
        }

        return new Working(
            $inputs,
            $steps,
            [$this->t('working.finance.interest_rule'), $this->t('working.finance.interest_note')],
            $result,
            $credit->soFar->toDecimal(2),
        );
    }

    private function quoteAsOf(DateTimeImmutable $date, DateTimeImmutable $today): AsOf
    {
        return AsOf::of(AsOfKind::Quote, $date, $today);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }
}
