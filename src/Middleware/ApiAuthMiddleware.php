<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Domain\Api\ApiKey;
use Logbook\Service\Api\ApiKeyAuthenticator;
use Logbook\Service\Api\KeyRefused;
use Logbook\Support\Api\ApiProblem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * API keys (spec.md §7.20): `Authorization: Bearer lbk_…` is the only way
 * in (ApiKeyAuthenticator). The key's user becomes the request's `user`
 * (replacing any session user, so a signed-in browser's cookie can never
 * write) and the access policy's and formatting's user; the key is the
 * `api_key` attribute, and a `read` key is refused anything but GET.
 *
 * A missing or bad key is a 401, a throttled address a 429, as problem
 * details.
 */
final readonly class ApiAuthMiddleware implements MiddlewareInterface
{
    public const string KEY = 'api_key';
    private const array READ_METHODS = ['GET', 'HEAD'];

    public function __construct(private ApiKeyAuthenticator $authenticator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $holder = $this->authenticator->authenticate($request);
        } catch (KeyRefused $refused) {
            throw new ApiProblem($refused->status, $refused->reason, $refused->getMessage(), [], $refused->headers);
        }

        if (!in_array($request->getMethod(), self::READ_METHODS, true) && !$holder->key->scope->canWrite()) {
            throw new ApiProblem(
                403,
                'insufficient_scope',
                'This key can only read. Create a "read and write" key to log entries.',
            );
        }

        $request = $request
            ->withAttribute(CurrentUserMiddleware::ATTRIBUTE, $holder->user)
            ->withAttribute(self::KEY, $holder->key);

        // Formatted text (`display`, names) in the owner's language and units.
        return $this->authenticator->as($holder, static fn (): ResponseInterface => $handler->handle($request));
    }

    public static function key(ServerRequestInterface $request): ApiKey
    {
        $key = $request->getAttribute(self::KEY);
        assert($key instanceof ApiKey, 'ApiAuthMiddleware must run first');

        return $key;
    }
}
