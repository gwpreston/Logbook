<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Logbook\Support\Config\AppSettings;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
        private AppSettings $settings,
    ) {
    }

    /**
     * After a save: back to the page the form was opened from (its
     * validated `return`, see ReturnTarget), else to the named route.
     *
     * @param array<string, string> $data route placeholders
     */
    public function backOr(ServerRequestInterface $request, string $name, array $data = []): ResponseInterface
    {
        $back = ReturnTarget::of($request, $this->settings->basePath);

        return $back !== null ? $this->to($back) : $this->toRoute($name, $data);
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
     * To the identity provider or sign-in proxy (spec.md §7.9): a URL from
     * its discovery document or from configuration, never from the request.
     */
    public function external(string $url): ResponseInterface
    {
        return $this->responses->createResponse(303)
            ->withHeader('Location', $url)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
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
