<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use Slim\Interfaces\RouteParserInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Template helpers. Every URL a template emits must come from `url_for`,
 * `base_path` or `asset` so the app works behind a subpath proxy.
 */
final class TwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RouteParserInterface $routeParser,
        private readonly AssetPackage $assets,
        private readonly TranslatorInterface&LocaleAwareInterface $translator,
        private readonly string $basePath,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('url_for', $this->urlFor(...)),
            new TwigFunction('asset', $this->assets->url(...)),
            new TwigFunction('base_path', fn (): string => $this->basePath),
            new TwigFunction('current_locale', $this->translator->getLocale(...)),
            new TwigFunction('html_lang', fn (): string => str_replace('_', '-', $this->translator->getLocale())),
            new TwigFunction('trans', $this->trans(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('trans', $this->trans(...)),
        ];
    }

    /**
     * @param array<string, string> $data
     * @param array<string, string> $query
     */
    public function urlFor(string $routeName, array $data = [], array $query = []): string
    {
        return $this->routeParser->urlFor($routeName, $data, $query);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null): string
    {
        return $this->translator->trans($id, $parameters, $domain);
    }
}
