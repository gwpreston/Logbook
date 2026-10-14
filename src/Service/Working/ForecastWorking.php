<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Forecast\ForecastSource;
use Logbook\Service\Forecast\ForecastTotals;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Service\Forecast\FuelEstimate;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Money;
use Logbook\Support\Working;
use Logbook\Support\WorkingInput;
use Logbook\Support\WorkingLink;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The working of a *Coming up* total (spec.md §7.18, §8 *Show the
 * working*): each item with its cost and the "last time" it comes from, the
 * finance payments, and the fuel estimate with the window it is projected
 * from, from the lines ForecastTotals added up. A total is the sum of the
 * month rows, so the lines are the months' own.
 */
final readonly class ForecastWorking
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
        private ForecastWording $wording,
    ) {
    }

    public function forTotals(ForecastTotals $totals): Working
    {
        $currency = $totals->currency;
        $inputs = [];
        $finance = Money::zero($currency);
        $unknown = [];
        foreach ($totals->months as $month) {
            foreach ($month->costed as $item) {
                $inputs[] = $this->itemInput($item);
            }
            foreach ($month->unknownItems as $item) {
                $unknown[] = $item;
            }
            if ($month->financeSpread !== null) {
                $finance = $finance->add($month->financeSpread);
            }
        }
        if (!$finance->isZero()) {
            $inputs[] = new WorkingInput($this->t('working.forecast.finance'), $this->format->money($finance));
        }
        foreach ($unknown as $item) {
            $inputs[] = new WorkingInput(
                $this->label($item),
                $this->t('working.forecast.no_cost', ['date' => $this->date($item)]),
                null,
                $this->link($item),
            );
        }

        $planned = $this->format->money($totals->planned());
        $steps = [];
        $count = count($totals->months);
        if ($totals->hasFuel) {
            $steps[] = $this->t('working.forecast.sum', [
                'planned' => $planned,
                'fuel' => $this->format->money($totals->fuel()),
                'total' => $this->format->money($totals->total()),
            ]);
            foreach ($totals->estimates as $estimate) {
                $inputs[] = new WorkingInput(
                    $this->t('working.forecast.fuel_for', ['vehicle' => $estimate->vehicle->name()]),
                    $this->format->money($this->fuelOver($estimate, $count)),
                );
                $steps[] = $this->fuelStep($estimate, $count);
            }
        } else {
            $steps[] = $this->t('working.forecast.sum_no_fuel', [
                'planned' => $planned,
                'total' => $this->format->money($totals->total()),
            ]);
        }
        if ($totals->unknown() > 0) {
            $steps[] = $this->t('working.forecast.unknown', ['count' => $totals->unknown()]);
        }

        return new Working(
            $inputs,
            $steps,
            [$this->t('working.forecast.not_assumed'), $this->t('working.forecast.undated')],
            $this->format->money($totals->total()),
            $totals->total()->toDecimal(Money::SCALE),
        );
    }

    private function itemInput(ForecastItem $item): WorkingInput
    {
        return new WorkingInput(
            $this->label($item),
            $this->t('working.forecast.item', [
                'cost' => $this->format->money($item->cost),
                'date' => $this->date($item),
            ]),
            $item->costAsOf,
            $this->link($item),
        );
    }

    private function label(ForecastItem $item): string
    {
        return $item->vehicle->name() . ' · ' . $this->wording->title($item);
    }

    private function date(ForecastItem $item): string
    {
        return $item->dueOn === null ? $this->t('working.forecast.undated_date') : $this->format->date($item->dueOn);
    }

    private function link(ForecastItem $item): ?WorkingLink
    {
        $vehicle = ['id' => $item->vehicle->id];

        return match ($item->source) {
            ForecastSource::Schedule => new WorkingLink('maintenance.index', $vehicle),
            ForecastSource::Document, ForecastSource::FirstInspection => new WorkingLink('compliance.index', $vehicle),
            ForecastSource::Tyres => new WorkingLink('tyres.index', $vehicle),
            ForecastSource::Reminder => new WorkingLink('reminders.index'),
            // Finance needs Manage as well as costs: the page links it only for those who may open it.
            ForecastSource::Finance => null,
        };
    }

    private function fuelOver(FuelEstimate $estimate, int $months): Money
    {
        $sum = Money::zero($estimate->currency);
        foreach (array_slice($estimate->months, 0, $months) as $month) {
            $sum = $sum->add($month);
        }

        return $sum;
    }

    private function fuelStep(FuelEstimate $estimate, int $months): string
    {
        $window = $estimate->window;

        return $this->t('working.forecast.fuel', [
            'vehicle' => $estimate->vehicle->name(),
            'daily' => $estimate->kmPerDay === null ? '' : $this->format->distance($estimate->kmPerDay, 1),
            'rate' => $this->format->perDistance($estimate->perKm, $estimate->currency),
            'from' => $window === null || $window->from === null ? '' : $this->format->date($window->from),
            'to' => $window === null ? '' : $this->format->date($window->to),
            'amount' => $this->format->money($this->fuelOver($estimate, $months)),
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
