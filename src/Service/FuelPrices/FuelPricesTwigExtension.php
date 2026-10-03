<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\FuelPrices\ListedPrice;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Number\Decimal;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Fuel prices in templates (spec.md §7.34): whether prices are on and
 * which provider, "Listed £1.379/L at 14:20", and a saving or an extra
 * cost in words.
 */
final class FuelPricesTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly FuelPriceConfig $config,
        private readonly DisplayFormatter $formatter,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('fuel_prices_enabled', $this->config->enabled(...)),
            new TwigFunction('fuel_prices_available', $this->config->available(...)),
            new TwigFunction('fuel_price_provider', $this->config->provider(...)),
            new TwigFunction('listed_fresh', fn (ListedPrice $listed): bool => $listed->isFresh($this->clock->now())),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('listed_price', $this->listed(...)),
            new TwigFilter('price_saving', $this->saving(...)),
            new TwigFilter('signed_money', $this->signedMoney(...)),
        ];
    }

    /**
     * "Listed £1.379/L at 14:20" (the date too when not today).
     */
    public function listed(ListedPrice $listed, string $currency): string
    {
        return $this->translator->trans('fuel_prices.listed_at', [
            'price' => $this->formatter->unitPrice($listed->price, $currency, false, true),
            'when' => $this->formatter->instantWhen($listed->reportedAt, $this->clock->now()),
        ]);
    }

    /**
     * "saves £0.42", "costs £0.31 more", or "same cost".
     */
    public function saving(string $amount, string $currency): string
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

    /**
     * Money with its sign: "−£0.34".
     */
    public function signedMoney(string $amount, string $currency): string
    {
        $rounded = Decimal::round($amount, 2);
        $money = $this->formatter->money(ltrim($rounded, '-'), $currency);

        return Decimal::compare($rounded, '0') < 0 ? '−' . $money : $money;
    }
}
