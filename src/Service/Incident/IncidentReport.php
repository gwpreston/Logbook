<?php

declare(strict_types=1);

namespace Logbook\Service\Incident;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Money\Money;

/**
 * The Reports page's *Incidents* section (spec.md §7.7, §7.29), per
 * currency: the period's incidents (by date), *Incident-related spend*
 * (the report's own ledger lines that are linked to an incident, so spend
 * itself is unchanged) and *Payouts received* on those incidents.
 */
final readonly class IncidentReport
{
    public function __construct(
        private IncidentRepository $incidents,
        private VehicleService $vehicles,
        private FeatureToggles $features,
        private IncidentAccess $access,
    ) {
    }

    /**
     * @return list<IncidentReportLine> per currency, empty without incidents or with the module off
     */
    public function lines(User $user, Report $report): array
    {
        if (!$this->features->isEnabled(Feature::Incidents) || $report->vehicles === []) {
            return [];
        }
        $currencies = [];
        $vehicles = [];
        foreach ($report->vehicles as $vehicle) {
            $currencies[$vehicle->id] = $this->vehicles->currencyFor($user, $vehicle);
            $vehicles[$vehicle->id] = $vehicle;
        }

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, Money> $linked */
        $linked = [];
        /** @var array<string, Money> $payouts */
        $payouts = [];
        $period = $report->period;
        // *All time* starts at the earliest cost; an incident before it still counts.
        $from = $report->filter->period->range === ReportRange::AllTime ? null : $period->from;
        foreach ($this->incidents->listForVehicles(array_keys($currencies), $from, $period->to) as $incident) {
            $currency = $currencies[$incident->vehicleId];
            $counts[$currency] = ($counts[$currency] ?? 0) + 1;
            $payout = $incident->data->claim->payout;
            // A payout is a claim detail (spec.md §7.29 Access).
            if ($payout !== null && $this->access->seesDetails($user, $vehicles[$incident->vehicleId], $incident)) {
                $payouts[$currency] = ($payouts[$currency] ?? Money::zero($currency))->add(Money::of($payout, $currency));
            }
        }
        foreach ($report->items as $item) {
            if ($item->incidentId !== null) {
                $currency = $item->currency();
                $linked[$currency] = ($linked[$currency] ?? Money::zero($currency))->add($item->amount);
            }
        }

        $all = array_unique([...array_keys($counts), ...array_keys($linked)]);
        sort($all);
        $lines = [];
        foreach ($all as $currency) {
            $lines[] = new IncidentReportLine(
                $currency,
                $counts[$currency] ?? 0,
                $linked[$currency] ?? Money::zero($currency),
                $payouts[$currency] ?? Money::zero($currency),
            );
        }

        return $lines;
    }
}
