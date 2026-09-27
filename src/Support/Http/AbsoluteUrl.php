<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Logbook\Support\Config\AppSettings;
use Slim\Interfaces\RouteParserInterface;

/**
 * Absolute links to the app for places outside a browser page: notifications
 * and calendar feeds. APP_URL gives the scheme and host; the route already
 * carries APP_BASE_PATH (an APP_URL that repeats the base path is fine).
 */
final readonly class AbsoluteUrl
{
    public function __construct(
        private RouteParserInterface $routes,
        private AppSettings $settings,
    ) {
    }

    /**
     * @param array<string, string> $data
     * @param array<string, string> $query
     */
    public function route(string $name, array $data = [], array $query = []): string
    {
        return self::origin($this->settings->url, $this->settings->basePath) . $this->routes->urlFor($name, $data, $query);
    }

    /**
     * APP_URL without a trailing slash or a trailing copy of the base path.
     */
    public static function origin(string $appUrl, string $basePath): string
    {
        $origin = rtrim($appUrl, '/');
        if ($basePath !== '' && str_ends_with($origin, $basePath)) {
            $origin = substr($origin, 0, -strlen($basePath));
        }

        return $origin;
    }
}
