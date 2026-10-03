<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Number\Decimal;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The after-fill-up comparison and *Shopping around* in words (spec.md
 * §7.34): "Compared with your usual Tesco Antrim (£1.400/L): saved £2.00
 * on fuel, about £0.50 for the extra 4 mi, £1.50 better off".
 */
final readonly class ComparisonWording
{
    public function __construct(
        private DisplayFormatter $formatter,
        private TranslatorInterface $translator,
    ) {
    }

    public function sentence(FillUpComparison $comparison): string
    {
        $currency = $comparison->currency;
        $parts = [$this->fuelPart($comparison->fuelSaving, $currency)];
        if ($comparison->hasDistance() && $comparison->extraCost !== null && $comparison->extraRoadKm !== null) {
            $cost = Decimal::round($comparison->extraCost, 2);
            $parts[] = $this->translator->trans(
                Decimal::compare($cost, '0') >= 0 ? 'fuel_prices.compare.extra' : 'fuel_prices.compare.less_driving',
                [
                    'amount' => $this->formatter->money(ltrim($cost, '-'), $currency),
                    'distance' => $this->formatter->distance(abs($comparison->extraRoadKm)),
                ],
            );
            $parts[] = $this->result((string) $comparison->total, $currency);
        } else {
            $parts[] = $this->translator->trans('fuel_prices.compare.before_driving');
        }

        return $this->translator->trans('fuel_prices.compare.sentence', [
            'station' => $comparison->usual->data->name,
            'price' => $this->formatter->unitPrice($comparison->usualListed->price, $currency, false, true),
            'parts' => implode(', ', $parts),
        ]);
    }

    public function shoppingAround(ShoppingAround $shopping): string
    {
        $total = Decimal::round($shopping->total, 2);

        return $this->translator->trans(
            Decimal::compare($total, '0') >= 0 ? 'fuel_prices.shopping.better' : 'fuel_prices.shopping.worse',
            [
                'amount' => $this->formatter->money(ltrim($total, '-'), $shopping->currency),
                'count' => $shopping->fillUps,
            ],
        ) . ($shopping->withDistance ? '' : ' ' . $this->translator->trans('fuel_prices.shopping.before_driving'));
    }

    private function fuelPart(string $saving, string $currency): string
    {
        $rounded = Decimal::round($saving, 2);

        return $this->translator->trans(
            Decimal::compare($rounded, '0') >= 0 ? 'fuel_prices.compare.saved_fuel' : 'fuel_prices.compare.paid_more',
            ['amount' => $this->formatter->money(ltrim($rounded, '-'), $currency)],
        );
    }

    private function result(string $total, string $currency): string
    {
        $rounded = Decimal::round($total, 2);

        return $this->translator->trans(
            Decimal::compare($rounded, '0') >= 0 ? 'fuel_prices.compare.better_off' : 'fuel_prices.compare.worse_off',
            ['amount' => $this->formatter->money(ltrim($rounded, '-'), $currency)],
        );
    }
}
