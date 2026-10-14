<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimTotals;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Currency;
use Logbook\Support\Working;
use Logbook\Support\WorkingInput;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The working of a mileage claim (spec.md §7.23, §8 *Show the working*):
 * the business distance in each rate band × the rate, the passengers, and
 * what the employer paid, from the ClaimTotals the report shows. Each
 * line is the sum of its trips' rounded amounts, as the totals are.
 */
final readonly class ClaimWorking
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
    ) {
    }

    /**
     * @param string $period what the claim covers ("tax year 2026/27", "1 Apr to 30 Jun 2026")
     */
    public function forTotals(ClaimTotals $totals, string $period): Working
    {
        $currency = $totals->currency;
        $money = fn (string $amount): string => $this->format->money($amount, $currency);
        $inputs = [];
        $steps = [];
        foreach ($totals->rates as $band) {
            $distance = $this->format->number($band['distance'], 1) . ' ' . $this->t('units.symbol.' . $band['unit']->value);
            $rate = $this->format->rate($band['rate'], $currency);
            // The trips are the table above, on the same page: no link.
            $inputs[] = new WorkingInput($this->t('working.claim.band', ['rate' => $rate]), $distance);
            $steps[] = $this->t('working.claim.line', [
                'distance' => $distance,
                'rate' => $rate,
                'amount' => $money($band['amount']),
            ]);
        }
        $steps[] = $this->t('working.claim.mileage', ['amount' => $money($totals->mileageAmount), 'period' => $period]);
        if ($totals->passengerAmount !== '0') {
            $inputs[] = new WorkingInput($this->t('trip.claim.passengers_total'), $money($totals->passengerAmount));
            $steps[] = $this->t('working.claim.passengers', [
                'mileage' => $money($totals->mileageAmount),
                'passengers' => $money($totals->passengerAmount),
                'approved' => $money($totals->approvedAmount()),
            ]);
        }
        $notes = [];
        if ($totals->employerAmount !== null) {
            $difference = $totals->difference() ?? '0';
            $steps[] = $this->t(str_starts_with($difference, '-') ? 'working.claim.paid_above' : 'working.claim.not_paid', [
                'mileage' => $money($totals->mileageAmount),
                'employer' => $money($totals->employerAmount),
                'difference' => $money(ltrim($difference, '-')),
            ]);
            $notes[] = $this->t('trip.claim.difference_hint');
        }
        $notes[] = $this->t('working.claim.rounding', ['digits' => Currency::fractionDigits($currency)]);

        return new Working($inputs, $steps, $notes, $money($totals->approvedAmount()), $totals->approvedAmount());
    }

    /**
     * What a claim covers, as its working says it: "tax year 2026/27", or
     * "1 Apr 2026 to 30 Jun 2026".
     */
    public function periodLabel(ClaimFilter $filter): string
    {
        return $filter->taxYear !== null
            ? $this->t('trip.stat.tax_year', ['year' => $filter->taxYear->label()])
            : $this->t('report.period', [
                'from' => $this->format->date($filter->from),
                'to' => $this->format->date($filter->to),
            ]);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }
}
