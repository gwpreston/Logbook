<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use DateTimeImmutable;
use Logbook\Service\Report\OwnershipCost;
use Logbook\Service\Vehicle\DepreciationState;
use Logbook\Service\Vehicle\ValuePointKind;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Working;
use Logbook\Support\WorkingInput;
use Logbook\Support\WorkingLink;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The working of the cost of ownership (spec.md §7.7, §8 *Show the
 * working*): depreciation (the price paid against the latest value), the
 * running costs, the total and the cost per distance, from the
 * OwnershipCost the page shows. It carries the three sentences of *How it’s
 * worked out* that the tab had before, with their wording unchanged.
 */
final readonly class OwnershipWorking
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
    ) {
    }

    /**
     * The cost of ownership, part by part.
     */
    public function forCost(OwnershipCost $cost, DateTimeImmutable $today): Working
    {
        $vehicle = $cost->vehicle;
        $currency = $cost->currency;
        $inputs = [];
        $steps = [];
        $notes = [];

        $depreciation = $this->depreciation($cost, $today);
        if ($depreciation !== null) {
            array_push($inputs, ...$depreciation->inputs);
            array_push($steps, ...$depreciation->steps);
            array_push($notes, ...$depreciation->notes);
        }

        $running = $this->format->money($cost->running);
        $inputs[] = new WorkingInput(
            $this->t('working.ownership.running'),
            $running,
            null,
            new WorkingLink('expenses.index', ['id' => $vehicle->id]),
        );
        $window = ['from' => $this->format->date($cost->period->from), 'to' => $this->format->date($cost->period->to)];
        $steps[] = $cost->payouts === null
            ? $this->t('working.ownership.running_sum', ['amount' => $running, 'count' => $cost->count] + $window)
            : $this->t('working.ownership.running_sum_payouts', [
                'amount' => $running,
                'count' => $cost->count,
                'payouts' => $this->format->money($cost->payouts),
            ] + $window);

        // Build spend (Phase 50.1, #419): in the total, never in a rate.
        if ($cost->build !== null) {
            $inputs[] = new WorkingInput(
                $this->t('working.ownership.build'),
                $this->format->money($cost->build),
                null,
                new WorkingLink('parts.index', ['id' => $vehicle->id]),
            );
            $notes[] = $this->t('working.ownership.build_note');
        }
        if ($cost->total !== null && $cost->depreciationCost !== null) {
            $steps[] = $cost->build === null
                ? $this->t('working.ownership.total', [
                    'running' => $running,
                    'depreciation' => $this->format->money($cost->depreciationCost),
                    'total' => $this->format->money($cost->total),
                ])
                : $this->t('working.ownership.total_build', [
                    'running' => $running,
                    'build' => $this->format->money($cost->build),
                    'depreciation' => $this->format->money($cost->depreciationCost),
                    'total' => $this->format->money($cost->total),
                ]);
        }

        if ($cost->perKm !== null && $cost->distanceKm !== null) {
            $span = $cost->distanceSpan;
            $inputs[] = new WorkingInput(
                $this->t('working.ownership.distance'),
                $this->format->distance($cost->distanceKm),
                null,
                new WorkingLink('odometer.index', ['id' => $vehicle->id]),
            );
            if ($span !== null) {
                $steps[] = $this->t('working.true_cost.readings', [
                    'distance' => $this->format->distance($span->km),
                    'startOn' => $this->format->date($span->startOn),
                    'startKm' => $this->format->distance($span->startKm),
                    'endOn' => $this->format->date($span->endOn),
                    'endKm' => $this->format->distance($span->endKm),
                ]);
            }
            $steps[] = $this->t('working.ownership.rate_running', [
                'running' => $running,
                'distance' => $this->format->distance($cost->distanceKm),
                'rate' => $this->format->perDistance($cost->runningPerKm, $currency),
            ]);
            if ($cost->depreciationPerKm !== null) {
                $steps[] = $this->t('working.ownership.rate_depreciation', [
                    'rate' => $this->format->perDistance($cost->depreciationPerKm, $currency),
                    'date' => $this->format->date($cost->valuedOn()),
                ]);
                $steps[] = $this->t('working.ownership.rate_total', [
                    'running' => $this->format->perDistance($cost->runningPerKm, $currency),
                    'depreciation' => $this->format->perDistance($cost->depreciationPerKm, $currency),
                    'total' => $this->format->perDistance($cost->perKm, $currency),
                ]);
            } else {
                $notes[] = $this->t('working.ownership.rate_running_only');
            }
        }

        // The wording the tab's *How it’s worked out* card carried, unchanged.
        $steps[] = $this->t(
            $cost->depreciation->isSold() ? 'ownership.tab.how_depreciation_sold' : 'ownership.tab.how_depreciation',
        );
        $steps[] = $this->t('ownership.tab.how_finance');
        $steps[] = $this->t($vehicle->isArchived() ? 'ownership.tab.how_running_sold' : 'ownership.tab.how_running');

        return new Working(
            $inputs,
            $steps,
            $notes,
            $cost->perKm === null ? $running : $this->format->perDistance($cost->perKm, $currency),
            $cost->perKm ?? $cost->running->toDecimal(Money::SCALE),
        );
    }

    /**
     * The price paid against the latest value (or the sale price). Null
     * without a purchase price and a value.
     */
    public function depreciation(OwnershipCost $cost, DateTimeImmutable $today): ?Working
    {
        $figure = $cost->depreciation;
        $vehicle = $cost->vehicle;
        $current = $figure->current;
        $price = $vehicle->data->purchasePrice;
        if ($figure->state !== DepreciationState::Ready || $figure->change === null || $current === null || $price === null) {
            return null;
        }
        $currency = $cost->currency;
        $sold = $current->kind === ValuePointKind::Sold;
        $link = new WorkingLink($sold ? 'vehicles.show' : 'valuations.index', ['id' => $vehicle->id]);
        $inputs = [
            new WorkingInput(
                $this->t('working.ownership.price'),
                $this->format->money($price, $currency),
                null,
                new WorkingLink('vehicles.show', ['id' => $vehicle->id]),
            ),
            new WorkingInput(
                $this->t($sold ? 'working.ownership.sale_price' : 'working.ownership.latest_value'),
                $this->format->money($current->amount, $currency),
                $figure->asOf,
                $link,
            ),
        ];
        $loss = Decimal::subtract('0', $figure->change);
        $isGain = Decimal::compare($loss, '0') < 0;
        $step = $this->t($isGain ? 'working.ownership.depreciation_gain' : 'working.ownership.depreciation', [
            'price' => $this->format->money($price, $currency),
            'kind' => $this->t($sold ? 'working.ownership.kind_sale' : 'working.ownership.kind_latest'),
            'value' => $this->format->money($current->amount, $currency),
            'date' => $this->format->date($current->date),
            'amount' => $this->format->money($isGain ? Decimal::subtract('0', $loss) : $loss, $currency),
        ]);

        return new Working(
            $inputs,
            [$step],
            [$this->t('working.depreciation.not_assumed')],
            $this->format->money($loss, $currency),
            $loss,
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
