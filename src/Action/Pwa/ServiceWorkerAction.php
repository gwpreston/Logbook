<?php

declare(strict_types=1);

namespace Logbook\Action\Pwa;

use Logbook\Kernel;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\View\AssetPackage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;

/**
 * GET /sw.js — the service worker (spec.md §7.15). Served from the base
 * path so its scope covers the whole app, with its settings (base path,
 * the assets to cache, the offline page) prepended to the static script
 * in assets/js/service-worker.js. Never cached by the browser's HTTP
 * cache, so a new release is picked up on the next visit.
 */
final readonly class ServiceWorkerAction
{
    public function __construct(
        private AppSettings $settings,
        private AssetPackage $assets,
        private RouteParserInterface $routes,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $config = [
            'base' => $this->settings->basePath,
            'version' => Kernel::version() . '-' . $this->assets->version(),
            'assets' => $this->assets->urls('#^vendor/THIRD-PARTY-NOTICES\.txt$|^js/service-worker\.js$#'),
            'offline' => $this->routes->urlFor('pwa.offline'),
        ];
        $script = file_get_contents($this->settings->rootDir . '/public/assets/js/service-worker.js');

        $settings = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response->getBody()->write('self.LOGBOOK = ' . $settings . ";\n" . ($script === false ? '' : $script));

        return $response
            ->withHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
