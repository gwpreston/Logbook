<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Support\Http\CsrfFailedException;
use Logbook\Support\Http\PayloadTooLargeException;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Csrf\Guard;

/**
 * CSRF protection for every HTML route group, via slim/csrf.
 *
 * slim/csrf keeps its tokens in an array it is handed by reference; that
 * array lives in our database-backed session, so a Guard is built per
 * request around the current session's tokens (the one place a collaborator
 * is created with `new`: it wraps request-scoped state). Persistent-token mode
 * keeps one token per session so several tabs and the back button work; the
 * token is replaced whenever the session is regenerated (sign-in/out).
 *
 * Templates get the token from the `csrf_name` / `csrf_value` request
 * attributes (see Support\View\View).
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const string PREFIX = 'csrf';

    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = RequestContext::session($request);
        $tokens = $session->csrfTokens();
        $before = $tokens;

        $guard = new Guard($this->responses, self::PREFIX, $tokens, self::fail(...), 5, 16, true);
        $response = $guard->process($request, $handler);

        /** @var array<string, string> $tokens the Guard only ever stores strings */
        // A regenerated session must not inherit the old token. A redirect
        // renders no form, so a freshly minted token need not be stored (and
        // a drive-by request to "/" does not create a session).
        $isRedirect = $response->getStatusCode() >= 300 && $response->getStatusCode() < 400;
        if (!$session->wasRegenerated() && $tokens !== $before && !($isRedirect && $before === [])) {
            $session->setCsrfTokens($tokens);
        }

        return $response;
    }

    private static function fail(ServerRequestInterface $request): never
    {
        $body = $request->getParsedBody();
        $contentLength = (int) $request->getHeaderLine('Content-Length');
        if (($body === null || $body === []) && $contentLength > self::postMaxBytes()) {
            throw new PayloadTooLargeException($request);
        }

        throw new CsrfFailedException($request);
    }

    private static function postMaxBytes(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        } ?: PHP_INT_MAX;
    }
}
