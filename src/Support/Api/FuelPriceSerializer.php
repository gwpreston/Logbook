<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use DateTimeImmutable;
use Logbook\Domain\FuelPrices\ListedPrice;
use Logbook\Service\FuelPrices\NearResult;
use Logbook\Service\FuelPrices\NearRow;
use Logbook\Service\FuelPrices\PriceProvider;
use Logbook\Service\FuelPrices\StationPrices;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fuel prices as the API returns them (spec.md §7.20, §7.34): raw values
 * (decimal strings, kilometres, litres, UTC times) with display strings in
 * the key user's units, locale and currency, and the provider's
 * attribution. A position sent in the request is never echoed back.
 */
final readonly class FuelPriceSerializer
{
    public const int MONEY_SCALE = 2;

    public function __construct(
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function near(NearResult $result): array
    {
        $currency = $result->provider->currency();
        $profile = $result->profile;

        return [
            'provider' => $this->provider($result->provider),
            'currency' => $currency,
            'synced_at' => Serializer::instant($result->lastSync),
            'origin' => ['kind' => $result->origin->kind, 'label' => $result->origin->label],
            'vehicle_id' => $profile->vehicle->id,
            'grade' => $result->grade->value,
            'radius_km' => round($result->radiusKm, 3),
            'sort' => $result->sort->value,
            'include_older' => $result->includeOlder,
            'usual_fill' => [
                'litres' => Serializer::dec($profile->usualFill, 3),
                'assumed' => $profile->fillAssumed,
                'display' => $this->formatter->volume($profile->usualFill, 1),
            ],
            'economy' => $profile->hasEconomy() ? [
                'distance_km' => Serializer::dec($profile->economyKm, 3),
                'litres' => Serializer::dec($profile->economyLitres, 3),
                'all_time' => $profile->economyAllTime,
                'display' => $this->formatter->consumption($profile->economyKm, $profile->economyLitres),
            ] : null,
            'road_factor' => 1.3,
            'total' => $result->total,
            'items' => array_map(fn (NearRow $row): array => $this->row($row, $currency), $result->rows),
        ];
    }

    /**
     * A linked station's listed prices by grade, or null.
     *
     * @return list<array<string, mixed>>|null
     */
    public function listed(?StationPrices $prices): ?array
    {
        if ($prices === null) {
            return null;
        }

        return array_values(array_map(
            fn (ListedPrice $listed): array => $this->price($listed, $prices->provider->currency(), $prices->now),
            $prices->prices,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function provider(PriceProvider $provider): array
    {
        $licence = $provider->licence();

        return [
            'code' => $provider->code(),
            'name' => $this->translator->trans($provider->nameKey()),
            'attribution' => trim($this->translator->trans($licence->attributionKey) . ' ' . $licence->name . '.'),
            'licence_url' => $licence->url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NearRow $row, string $currency): array
    {
        $ps = $row->providerStation;
        $cost = $row->cost;
        $worth = $row->worthIt;
        $now = $this->clock->now();

        return [
            'station_id' => $row->station?->id,
            'provider_ref' => $ps->ref(),
            'name' => $ps->data->name,
            'brand' => $ps->data->brand,
            'address' => $ps->data->address,
            'postcode' => $ps->data->postcode,
            'latitude' => $ps->data->latitude,
            'longitude' => $ps->data->longitude,
            'distance_km' => round($cost->km, 3),
            'listed' => $this->price($row->listed, $currency, $now) + ['fresh' => $row->fresh],
            'usual_fill_litres' => Serializer::dec($cost->fill, 3),
            'detour_km' => round($cost->detourKm, 3),
            'detour_litres' => Serializer::dec($cost->detourLitres, 3),
            'effective_cost' => Serializer::dec($cost->total, self::MONEY_SCALE),
            'nearest' => $row->isNearest(),
            'worth_it' => $worth === null ? null : [
                'fuel_saving' => Serializer::dec($worth->fuelSaving, self::MONEY_SCALE),
                'extra_km' => round($worth->extraKm, 3),
                'extra_road_km' => round($worth->extraRoadKm, 3),
                'fuel_for_that' => Serializer::dec($worth->fuelForThat, self::MONEY_SCALE),
                'actual_saving' => Serializer::dec($worth->actualSaving, self::MONEY_SCALE),
                'worth_the_trip' => $worth->isWorthIt(),
            ],
            'display' => [
                'distance' => $this->formatter->distance($cost->km, 1),
                'effective_cost' => $this->translator->trans('fuel_prices.near.effective', [
                    'amount' => $this->formatter->money($cost->total, $currency),
                    'volume' => $this->formatter->volume($cost->fill, 1),
                ]),
                'saves' => $worth === null ? $this->translator->trans('fuel_prices.near.nearest') : $this->saves($worth->actualSaving, $currency),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function price(ListedPrice $listed, string $currency, DateTimeImmutable $now): array
    {
        return [
            'grade' => $listed->grade->value,
            'price' => Serializer::dec($listed->price, 3),
            'currency' => $currency,
            'reported_at' => Serializer::instant($listed->reportedAt),
            'fresh' => $listed->isFresh($now),
            'display' => $this->formatter->unitPrice($listed->price, $currency, false, true),
        ];
    }

    private function saves(string $amount, string $currency): string
    {
        $rounded = Decimal::round($amount, 2);
        $sign = Decimal::compare($rounded, '0');
        if ($sign === 0) {
            return $this->translator->trans('fuel_prices.same');
        }

        return $this->translator->trans($sign > 0 ? 'fuel_prices.saves' : 'fuel_prices.costs_more', [
            'amount' => $this->formatter->money(ltrim($rounded, '-'), $currency),
        ]);
    }
}
