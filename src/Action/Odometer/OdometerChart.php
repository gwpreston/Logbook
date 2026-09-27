<?php

declare(strict_types=1);

namespace Logbook\Action\Odometer;

use Logbook\Service\Odometer\OdometerHistory;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The mileage trend: every reading over time, in the user's distance unit.
 */
final readonly class OdometerChart
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function build(OdometerHistory $history, DisplayPreferences $preferences): LineChart
    {
        $unit = $preferences->distanceUnit;
        $points = [];
        foreach ($history->readings as $reading) {
            $points[] = [$reading->recordedAt, $unit->fromKm((float) $reading->readingKm)];
        }

        return (new LineChart($preferences, $this->translator->trans('units.name.' . $unit->value), 0))
            ->addSeries($this->translator->trans('odometer.chart.series'), $points);
    }
}
