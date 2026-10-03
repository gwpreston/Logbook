<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Station\Station;
use Logbook\Domain\User\User;
use Logbook\Service\Station\StationService;
use Logbook\Support\Display\DisplayFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The fill-up form's hint at a linked station (spec.md §7.34 *Fill-up
 * form*): "Listed £1.379/L E10 95 at 14:20 · Last time you paid £1.389",
 * per grade with a fresh price, and the price per unit (in the user's
 * volume unit) that *Use listed price* puts in the field. Never filled on
 * its own.
 */
final readonly class ListedHint
{
    public function __construct(
        private ListedPrices $listed,
        private StationService $stations,
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
        private FuelPricesTwigExtension $wording,
    ) {
    }

    /**
     * @param list<Station> $stations
     * @return array<int, array<string, array{text: string, price: string}>> by station id, then grade code
     */
    public function forStations(User $user, array $stations): array
    {
        $hints = [];
        foreach ($this->listed->forStations($stations) as $stationId => $prices) {
            $currency = $prices->provider->currency();
            $last = $this->stations->lastTimeHere($user, $stationId);
            $paid = $last === null || $last->currency !== $currency
                ? null
                : $this->formatter->unitPrice($last->entry->data->pricePerUnit, $currency, false, true);
            foreach ($prices->prices as $grade => $listed) {
                if (!$listed->isFresh($prices->now)) {
                    continue;
                }
                $text = $this->translator->trans('fuel_prices.hint.listed', [
                    'listed' => $this->wording->listed($listed, $currency),
                    'grade' => $this->translator->trans($listed->grade->shortLabelKey()),
                ]);
                if ($paid !== null) {
                    $text .= ' · ' . $this->translator->trans('fuel_prices.hint.last_paid', ['price' => $paid]);
                }
                $hints[$stationId][$grade] = [
                    'text' => $text,
                    'price' => $user->preferences->volumeUnit->pricePerUnit($listed->price, 3),
                ];
            }
        }

        return $hints;
    }
}
