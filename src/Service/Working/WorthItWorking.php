<?php

declare(strict_types=1);

namespace Logbook\Service\Working;

use Logbook\Service\FuelPrices\FuelPricesTwigExtension;
use Logbook\Service\FuelPrices\NearResult;
use Logbook\Service\FuelPrices\NearRow;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Working;
use Logbook\Support\WorkingInput;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The working of *Was it worth it?* (spec.md §7.34, §8 *Show the working*):
 * the four steps the *Cheapest near me* table had before, with their wording
 * unchanged, now on the shared macro. Written from the WorthIt the row shows.
 */
final readonly class WorthItWorking
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $format,
        private FuelPricesTwigExtension $prices,
    ) {
    }

    /**
     * Null for the nearest station, which every other one is compared with.
     */
    public function forRow(NearRow $row, NearResult $result): ?Working
    {
        $worth = $row->worthIt;
        if ($worth === null) {
            return null;
        }
        $profile = $result->profile;
        $currency = $result->provider->currency();
        $signed = fn (string $amount): string => $this->prices->signedMoney($amount, $currency);

        $steps = [
            $this->translator->trans(
                str_starts_with($worth->priceDifference, '-')
                    ? 'fuel_prices.worth.fuel_saving_more'
                    : 'fuel_prices.worth.fuel_saving',
                [
                    'amount' => $signed($worth->fuelSaving),
                    'volume' => $this->format->volume($row->cost->fill, 1),
                    'difference' => $this->format->unitPrice(ltrim($worth->priceDifference, '-'), $currency, false, true),
                ],
            ),
            $this->translator->trans('fuel_prices.worth.extra_distance', [
                'straight' => $this->format->distance($worth->extraKm, 1),
                'road' => $this->format->distance($worth->extraRoadKm, 1),
            ]),
            $profile->hasEconomy()
                ? $this->translator->trans('fuel_prices.worth.fuel_for_that', [
                    'amount' => $signed($worth->fuelForThat),
                    'economy' => $this->format->consumption($profile->economyKm, $profile->economyLitres),
                ])
                : $this->translator->trans('fuel_prices.worth.no_economy'),
            $this->translator->trans($worth->isWorthIt() ? 'fuel_prices.worth.worth_it' : 'fuel_prices.worth.not_worth_it', [
                'amount' => $signed($worth->actualSaving),
            ]),
        ];

        return new Working(
            [new WorkingInput(
                $this->translator->trans('working.worth.listed'),
                $this->prices->listed($row->listed, $currency),
            )],
            $steps,
            [],
            $this->prices->saving($worth->actualSaving, $currency),
            $worth->actualSaving,
        );
    }
}
