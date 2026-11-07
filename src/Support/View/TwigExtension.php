<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use Closure;
use DateTimeImmutable;
use Logbook\Kernel;
use Logbook\Service\Notification\Personal\ReplyWords;
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
            new TwigFilter('mileage_rate', $this->formatter->rate(...)),
            new TwigFilter('chart_value', $this->formatter->chartValue(...)),
            // Figures with a unit: on screen the number never parts from its unit.
            new TwigFilter('distance', self::keepTogether($this->formatter->distance(...))),
            new TwigFilter('volume', self::keepTogether($this->formatter->volume(...))),
            new TwigFilter('depth', self::keepTogether($this->formatter->depth(...))),
            new TwigFilter('about_distance', self::keepTogether($this->formatter->aboutDistance(...))),
            new TwigFilter('energy', self::keepTogether($this->formatter->energy(...))),
            new TwigFilter('quantity', self::keepTogether($this->formatter->quantity(...))),
            new TwigFilter('consumption', self::keepTogether($this->formatter->consumption(...))),
            new TwigFilter('efficiency', self::keepTogether($this->formatter->efficiency(...))),
            new TwigFilter('economy', self::keepTogether($this->formatter->economy(...))),
            new TwigFilter('unit_price', self::keepTogether($this->formatter->unitPrice(...))),
            new TwigFilter('per_distance', self::keepTogether($this->formatter->perDistance(...))),
            new TwigFilter('per_thousand_distance', self::keepTogether($this->formatter->perThousandDistance(...))),
            new TwigFilter('file_size', self::keepTogether($this->formatter->fileSize(...))),
            // The same for a figure a template joins itself: (n|number ~ ' ' ~ symbol)|keep_together.
            new TwigFilter('keep_together', self::keepTogether(static fn (string $figure): string => $figure)),
            new TwigFilter('local_date', $this->formatter->date(...)),
            new TwigFilter('local_datetime', $this->formatter->dateTime(...)),
            new TwigFilter('instant_date', $this->formatter->instantDate(...)),
            new TwigFilter('local_month', $this->formatter->month(...)),
            new TwigFilter('month_name', $this->formatter->monthName(...)),
            new TwigFilter('date_skeleton', $this->formatter->skeleton(...)),
            new TwigFilter('channel_error', $this->channelError(...)),
        ];
    }

    /**
     * Wraps a unit formatter so its figure stays on one line on screen:
     * every ordinary space ("12,480 mi", "48.7 mpg (US)", "£1.459/US gal")
     * becomes a no-break space (U+00A0), so a narrow column wraps the figure
     * as a whole rather than leaving "mi" alone on the next line. Only the
     * Twig filters do this: DisplayFormatter also writes API, MCP, AI and
     * notification text, which keeps its plain spaces.
     *
     * The variadic wrapper takes positional arguments only, as every
     * template passes them; Twig's named arguments would not reach it.
     *
     * @param Closure $format a DisplayFormatter method returning the figure
     *
     * @return Closure(mixed...): string
     */
    public static function keepTogether(Closure $format): Closure
    {
        return static function (mixed ...$args) use ($format): string {
            $figure = $format(...$args);

            return is_string($figure) ? str_replace(' ', "\u{00A0}", $figure) : '';
        };
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
     * A channel's error: a service's stored words (ReplyWords) in the
     * reader's language, anything else as it is (spec.md §7.11).
     */
    public function channelError(?string $error): string
    {
        $words = $error === null ? null : ReplyWords::decode($error);

        return $words === null ? (string) $error : $this->translator->trans($words['key'], $words['params']);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null): string
    {
        return $this->translator->trans($id, $parameters, $domain);
    }
}
