<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use DateTimeImmutable;
use Logbook\Kernel;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Money\Currency;
use Psr\Clock\ClockInterface;
use Slim\Interfaces\RouteParserInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Template helpers. Every URL a template emits must come from `url_for`,
 * `base_path` or `asset` so the app works behind a subpath proxy. Every
 * number, unit, amount and date goes through the formatting filters so the
 * user's preferences apply everywhere:
 *
 *   {{ km|distance }}  {{ litres|volume }}  {{ kwh|energy }}
 *   {{ amount|money(currency) }}  {{ n|number(2) }}
 *   {{ volume|quantity(electric) }}  {{ km|economy(volume, electric) }}
 *   {{ per_litre|unit_price(currency, electric) }}  {{ per_km|per_distance(currency) }}
 *   {{ calendar_date|local_date }}  {{ instant|local_datetime }}  {{ instant|instant_date }}
 *   {{ bytes|file_size }}
 */
final class TwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RouteParserInterface $routeParser,
        private readonly AssetPackage $assets,
        private readonly TranslatorInterface&LocaleAwareInterface $translator,
        private readonly DisplayFormatter $formatter,
        private readonly DisplayContext $display,
        private readonly ClockInterface $clock,
        private readonly string $basePath,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('url_for', $this->urlFor(...)),
            new TwigFunction('asset', $this->assets->url(...)),
            new TwigFunction('base_path', fn (): string => $this->basePath),
            new TwigFunction('app_version', Kernel::version(...)),
            new TwigFunction('current_locale', $this->translator->getLocale(...)),
            new TwigFunction('html_lang', fn (): string => str_replace('_', '-', $this->translator->getLocale())),
            new TwigFunction('trans', $this->trans(...)),
            new TwigFunction('prefs', fn (): DisplayPreferences => $this->display->preferences()),
            // Today's calendar date in the user's time zone (the print header's "Printed …").
            new TwigFunction('today', fn (): DateTimeImmutable => LocalTime::today(
                $this->clock,
                $this->display->preferences()->timeZone(),
            )),
            new TwigFunction('currency_name', fn (string $code): string => Currency::name($code, $this->locale())),
            new TwigFunction('currency_symbol', fn (string $code): string => Currency::symbol($code, $this->locale())),
            new TwigFunction('currency_digits', Currency::fractionDigits(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('trans', $this->trans(...)),
            new TwigFilter('number', $this->formatter->number(...)),
            new TwigFilter('percent', $this->formatter->percent(...)),
            new TwigFilter('money', $this->formatter->money(...)),
            new TwigFilter('chart_value', $this->formatter->chartValue(...)),
            new TwigFilter('distance', $this->formatter->distance(...)),
            new TwigFilter('volume', $this->formatter->volume(...)),
            new TwigFilter('depth', $this->formatter->depth(...)),
            new TwigFilter('about_distance', $this->formatter->aboutDistance(...)),
            new TwigFilter('energy', $this->formatter->energy(...)),
            new TwigFilter('quantity', $this->formatter->quantity(...)),
            new TwigFilter('consumption', $this->formatter->consumption(...)),
            new TwigFilter('efficiency', $this->formatter->efficiency(...)),
            new TwigFilter('economy', $this->formatter->economy(...)),
            new TwigFilter('unit_price', $this->formatter->unitPrice(...)),
            new TwigFilter('per_distance', $this->formatter->perDistance(...)),
            new TwigFilter('per_thousand_distance', $this->formatter->perThousandDistance(...)),
            new TwigFilter('file_size', $this->formatter->fileSize(...)),
            new TwigFilter('local_date', $this->formatter->date(...)),
            new TwigFilter('local_datetime', $this->formatter->dateTime(...)),
            new TwigFilter('instant_date', $this->formatter->instantDate(...)),
            new TwigFilter('local_month', $this->formatter->month(...)),
            new TwigFilter('month_name', $this->formatter->monthName(...)),
        ];
    }

    /**
     * @param array<string, int|string> $data
     * @param array<string, int|string|list<int|string>> $query a list becomes `name[]=…` (the sale pack's kinds)
     */
    public function urlFor(string $routeName, array $data = [], array $query = []): string
    {
        return $this->routeParser->urlFor(
            $routeName,
            array_map(strval(...), $data),
            array_map(
                static fn (int|string|array $v): string|array => is_array($v) ? array_map(strval(...), $v) : (string) $v,
                $query,
            ),
        );
    }

    private function locale(): string
    {
        return $this->translator->getLocale();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null): string
    {
        return $this->translator->trans($id, $parameters, $domain);
    }
}
