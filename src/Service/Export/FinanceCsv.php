<?php

declare(strict_types=1);

namespace Logbook\Service\Export;

use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Finance\AgreementView;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Csv\CsvNumber;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Finance agreements and their schedules as CSV rows (spec.md §7.13
 * *Finance*, §7.32 *Agreement page*): one row per payment, scheduled or
 * not, each with its agreement. Never the agreement number (#120).
 */
final readonly class FinanceCsv
{
    public function __construct(
        private FinanceService $finance,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Every agreement of the vehicle, oldest first.
     *
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    public function vehicleTable(User $user, Vehicle $vehicle): array
    {
        $rows = [];
        foreach (array_reverse($this->finance->forVehicle($user, $vehicle)) as $agreement) {
            array_push($rows, ...$this->rows($this->finance->view($user, $vehicle, $agreement)));
        }

        return [$this->header(), $rows];
    }

    /**
     * One agreement's schedule.
     *
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    public function scheduleTable(AgreementView $view): array
    {
        return [$this->header(), $this->rows($view)];
    }

    /**
     * @return list<string>
     */
    private function header(): array
    {
        return array_map($this->t(...), [
            'finance.column.type',
            'finance.column.lender',
            'finance.column.started_on',
            'finance.column.agreement_status',
            'finance.column.number',
            'export.column.date',
            'finance.column.payment',
            'export.column.amount',
            'export.column.currency',
            'finance.column.status',
            'finance.column.paid_on',
        ]);
    }

    /**
     * @return list<list<string|null>>
     */
    private function rows(AgreementView $view): array
    {
        $agreement = $view->agreement;
        $currency = $view->currency;
        $schedule = $view->figures->schedule;
        $common = [
            $this->t($agreement->type()->labelKey()),
            $agreement->data->lender,
            $agreement->data->startedOn->format('Y-m-d'),
            $this->t($agreement->status->labelKey()),
        ];

        $rows = [];
        foreach ($schedule->payments as $payment) {
            $rows[] = [$payment->dueOn->format('Y-m-d'), [
                ...$common,
                (string) $payment->number,
                $payment->dueOn->format('Y-m-d'),
                $this->t('finance.payment_kind.' . $payment->kind->value),
                CsvNumber::money($payment->amount, $currency),
                $currency,
                $this->t($payment->status->labelKey()),
                $payment->paidOn?->format('Y-m-d'),
            ]];
        }
        $events = [...$schedule->extras, ...($schedule->settlement === null ? [] : [$schedule->settlement])];
        foreach ($events as $event) {
            $date = $event->paymentDate();
            $rows[] = [$date?->format('Y-m-d') ?? '', [
                ...$common,
                null,
                $date?->format('Y-m-d'),
                $this->t($event->kind === PaymentEventKind::Settlement ? 'finance.event.settlement' : 'finance.event.extra'),
                $event->amount === null ? null : CsvNumber::money($event->amount, $currency),
                $currency,
                $this->t('finance.payment_status.paid'),
                $date?->format('Y-m-d'),
            ]];
        }
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $row): array => $row[1], $rows);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key);
    }
}
