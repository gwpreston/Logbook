<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use DateTimeImmutable;
use Logbook\Service\Forecast\ForecastTotals;
use Logbook\Service\FuelPrices\NearResult;
use Logbook\Service\FuelPrices\NearRow;
use Logbook\Service\Fuel\FillEconomy;
use Logbook\Service\Report\CostChange;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\OwnershipCost;
use Logbook\Service\Report\TrueCost;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimTotals;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Working;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `working_of(figure, context)`: how a headline figure was worked out
 * (spec.md §8 *Show the working*), for `ui.working()`. The figure is the
 * result object a page already shows (an OwnershipCost, a TrueCost with
 * its VehicleTrueCost, a CostChange with its VehicleTrueCost); the words
 * are written from its own values, so the page only places them. A page
 * calls it where it already shows the figure, so a viewer without
 * `ViewCosts` never reaches it.
 */
final class WorkingTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly OwnershipWorking $ownership,
        private readonly TrueCostWorking $trueCost,
        private readonly EconomyWorking $economy,
        private readonly ClaimWorking $claim,
        private readonly ForecastWorking $forecast,
        private readonly WorthItWorking $worth,
        private readonly ReportWorking $report,
        private readonly DisplayContext $display,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('working_of', $this->workingOf(...)),
        ];
    }

    public function workingOf(?object $figure, ?object $context = null): ?Working
    {
        return match (true) {
            $figure instanceof OwnershipCost => $this->ownership->forCost($figure, $this->today()),
            $figure instanceof TrueCost && $context instanceof VehicleTrueCost
                => $this->trueCost->period($context, $figure, $this->today()),
            $figure instanceof ClaimTotals && $context instanceof ClaimFilter
                => $this->claim->forTotals($figure, $this->claim->periodLabel($context)),
            $figure instanceof NearRow && $context instanceof NearResult => $this->worth->forRow($figure, $context),
            $figure instanceof CurrencyReport => $this->report->costPerDistance($figure),
            $figure instanceof ForecastTotals => $this->forecast->forTotals($figure),
            $figure instanceof FillEconomy => $this->economy->forFill($figure),
            $figure instanceof CostChange && $context instanceof VehicleTrueCost => $this->trueCost->change($context, $figure),
            default => null,
        };
    }

    private function today(): DateTimeImmutable
    {
        return LocalTime::today($this->clock, $this->display->preferences()->timeZone());
    }
}
