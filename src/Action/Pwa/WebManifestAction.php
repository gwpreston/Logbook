<?php

declare(strict_types=1);

namespace Logbook\Action\Pwa;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\View\AssetPackage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET /manifest.webmanifest — the web app manifest (spec.md §7.15). Served by
 * the app rather than as a static file so start_url, scope and icons carry
 * APP_BASE_PATH, and names are in the visitor's language.
 */
final readonly class WebManifestAction
{
    public function __construct(
        private AppSettings $settings,
        private AssetPackage $assets,
        private TranslatorInterface $translator,
        private FeatureToggles $features,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $base = $this->settings->basePath;
        $locale = $request->getAttribute('locale');

        $manifest = [
            'name' => $this->translator->trans('app.name'),
            'short_name' => $this->translator->trans('app.name'),
            'description' => $this->translator->trans('app.tagline'),
            'lang' => str_replace('_', '-', is_string($locale) ? $locale : 'en'),
            'id' => $base . '/',
            'start_url' => $base . '/',
            'scope' => $base . '/',
            'display' => 'standalone',
            'background_color' => '#0a111c',
            'theme_color' => '#0a111c',
            'icons' => [
                ['src' => $this->assets->url('images/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $this->assets->url('images/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                [
                    'src' => $this->assets->url('images/icon-maskable-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];
        $shortcuts = [];
        if ($this->features->isEnabled(Feature::Fuel)) {
            $shortcuts[] = ['name' => $this->translator->trans('nav.log_fill_up'), 'url' => $base . '/fuel/new'];
        }
        if ($this->features->isEnabled(Feature::Trips)) {
            $shortcuts[] = ['name' => $this->translator->trans('nav.log_trip'), 'url' => $base . '/log/new/trip'];
        }
        if ($shortcuts !== []) {
            $manifest['shortcuts'] = $shortcuts;
        }

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        $response->getBody()->write(json_encode($manifest, $flags));

        return $response
            ->withHeader('Content-Type', 'application/manifest+json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
