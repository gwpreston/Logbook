<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use Logbook\Service\Report\CurrencyReport;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Working;
use Logbook\Support\WorkingInput;
use Logbook\Support\WorkingLink;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The working of the Reports page's cost per distance (spec.md §7.7, §8
 * *Show the working*): the costs logged in the period ÷ the distance driven
 * by the vehicles that have costs in it, with the two readings each
 * vehicle's distance is the difference of. Depreciation (and any build
 * spend) is left out of this figure, and the working says so.
 */
final readonly class ReportWorking
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
    ) {
    }

    /**
     * Null without a cost per distance (no costs, or no distance).
     */
    public function costPerDistance(CurrencyReport $section): ?Working
    {
        if ($section->costPerKm === null || $section->distanceKm === null) {
            return null;
        }
        $currency = $section->currency;
        $inputs = [];
        $steps = [$this->t('working.report.rate', [
            'total' => $this->format->money($section->total),
            'distance' => $this->format->distance($section->distanceKm),
            'rate' => $this->format->perDistance($section->costPerKm, $currency),
        ])];
        foreach ($section->vehicles as $cost) {
            $inputs[] = new WorkingInput(
                $cost->vehicle->name(),
                $cost->distanceKm === null
                    ? $this->t('working.report.no_distance', ['total' => $this->format->money($cost->total)])
                    : $this->t('working.report.vehicle', [
                        'total' => $this->format->money($cost->total),
                        'distance' => $this->format->distance($cost->distanceKm),
                    ]),
                null,
                new WorkingLink('expenses.index', ['id' => $cost->vehicle->id]),
            );
            if ($cost->span !== null) {
                $steps[] = $cost->vehicle->name() . ': ' . $this->t('working.true_cost.readings', [
                    'distance' => $this->format->distance($cost->span->km),
                    'startOn' => $this->format->date($cost->span->startOn),
                    'startKm' => $this->format->distance($cost->span->startKm),
                    'endOn' => $this->format->date($cost->span->endOn),
                    'endKm' => $this->format->distance($cost->span->endKm),
                ]);
            }
        }

        return new Working(
            $inputs,
            $steps,
            [$this->t('working.report.left_out'), $this->t('working.report.distance_note')],
            $this->format->perDistance($section->costPerKm, $currency),
            $section->costPerKm,
        );
    }

    /**
     * @param array<string, int|string> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }
}
