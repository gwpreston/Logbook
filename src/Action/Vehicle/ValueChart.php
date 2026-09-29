<?php

declare(strict_types=1);

namespace Logbook\Action\Vehicle;

use Logbook\Service\Vehicle\Depreciation;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The value over time on the overview's *Ownership* card (spec.md §7.1):
 * the purchase, every valuation and the sale, in the vehicle's currency.
 */
final readonly class ValueChart
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function build(Depreciation $depreciation, DisplayPreferences $preferences): LineChart
    {
        $points = [];
        foreach ($depreciation->points as $point) {
            $points[] = [$point->date, (float) $point->amount];
        }

        return (new LineChart($preferences, $depreciation->currency, 0, $depreciation->currency, true))
            ->addSeries($this->translator->trans('valuation.chart.series'), $points);
    }
}
