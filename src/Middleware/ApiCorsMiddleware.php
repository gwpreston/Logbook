<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Support\Api\ApiPath;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Config\AppSettings;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS for the API, off unless API_CORS_ORIGINS lists origins (spec.md
 * §7.20). A listed origin gets Access-Control-Allow-Origin on every API
 * response, errors included, and its preflight (OPTIONS) a 204; a
 * preflight from any other origin, or with CORS off, is refused.
 * Credentials are never allowed: the API key travels in a header the page
 * sets.
 *
 * Global and outermost, so it answers preflights before routing (no
 * OPTIONS routes) and marks the router's own 404s too; anything that is
 * not an API path or the MCP endpoint (§7.28, which also allows its own
 * headers) passes straight through.
 */
final readonly class ApiCorsMiddleware implements MiddlewareInterface
{
    private const string METHODS = 'GET, POST, OPTIONS';
    private const string HEADERS = 'Authorization, Content-Type';
    /** The MCP transport's own request headers (spec.md §7.28). */
    private const string MCP_HEADERS = 'Authorization, Content-Type, MCP-Protocol-Version, Mcp-Method, Mcp-Name';
    private const int MAX_AGE = 600;

    public function __construct(
        private AppSettings $settings,
        private ResponseFactoryInterface $responses,
        private ApiResponder $responder,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $mcp = $this->settings->mcpRouted() && ApiPath::isMcp($path, $this->settings->basePath);
        if (!$mcp && (!$this->settings->apiEnabled || !ApiPath::matches($path, $this->settings->basePath))) {
            return $handler->handle($request);
        }

        $origin = strtolower(rtrim($request->getHeaderLine('Origin'), '/'));
        $allowed = $origin !== '' && in_array($origin, $this->settings->apiCorsOrigins, true);

        if ($request->getMethod() === 'OPTIONS') {
            if (!$allowed) {
                return $this->responder->problem(new ApiProblem(
                    403,
                    'cors_not_allowed',
                    'This origin may not call the API from a browser. See API_CORS_ORIGINS.',
                ));
            }

            return $this->withCors($this->responses->createResponse(204), $origin)
                ->withHeader('Access-Control-Allow-Methods', self::METHODS)
                ->withHeader('Access-Control-Allow-Headers', $mcp ? self::MCP_HEADERS : self::HEADERS)
                ->withHeader('Access-Control-Max-Age', (string) self::MAX_AGE);
        }

        $response = $handler->handle($request);
        if ($allowed) {
            return $this->withCors($response, $origin);
        }

        // Answers differ by origin once any are allowed: keep caches apart.
        return $this->settings->apiCorsOrigins === [] ? $response : $response->withAddedHeader('Vary', 'Origin');
    }

    private function withCors(ResponseInterface $response, string $origin): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withAddedHeader('Vary', 'Origin');
    }
}
