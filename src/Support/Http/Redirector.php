<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Interfaces\RouteParserInterface;

/**
 * Redirect responses to named routes (always under APP_BASE_PATH). Uses
 * 303 See Other so a redirect after a POST is followed with a GET.
 */
final readonly class Redirector
{
    public function __construct(
        private RouteParserInterface $routes,
        private ResponseFactoryInterface $responses,
    ) {
    }

    /**
     * @param array<string, string> $data route placeholders
     * @param array<string, string> $query
     */
    public function toRoute(string $name, array $data = [], array $query = []): ResponseInterface
    {
        return $this->to($this->routes->urlFor($name, $data, $query));
    }

    /**
     * @param string $url an already-validated local URL (see SafeRedirect)
     */
    public function to(string $url): ResponseInterface
    {
        return $this->responses->createResponse(303)->withHeader('Location', $url);
    }

    /**
     * @param array<string, string> $data
     * @param array<string, string> $query
     */
    public function urlFor(string $name, array $data = [], array $query = []): string
    {
        return $this->routes->urlFor($name, $data, $query);
    }
}
