<?php

declare(strict_types=1);

namespace Logbook\Action\SalePack;

use Logbook\Service\SalePack\EvidenceReading;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\View\LineChart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sale pack's mileage chart (spec.md §7.19): the readings of the
 * mileage record, odometer against date, in the print palette. The table
 * beside it holds the same figures.
 */
final readonly class SalePackChart
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param list<EvidenceReading> $readings
     */
    public function build(array $readings, DisplayPreferences $preferences): LineChart
    {
        $unit = $preferences->distanceUnit;
        $points = array_map(
            static fn (EvidenceReading $e): array => [$e->reading->recordedAt, $unit->fromKm((float) $e->reading->readingKm)],
            $readings,
        );

        return (new LineChart($preferences, $this->translator->trans('units.name.' . $unit->value), 0))
            ->addSeries($this->translator->trans('sale_pack.mileage.series'), $points)
            ->forPrint();
    }
}
