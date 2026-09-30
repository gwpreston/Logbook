<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Domain\Api\ApiKey;
use Logbook\Repository\UserRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\FailedKeyThrottle;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * API keys (spec.md §7.20): `Authorization: Bearer lbk_…` is the only way
 * in. The key's user becomes the request's `user` (replacing any session
 * user, so a signed-in browser's cookie can never write) and the access
 * policy's and formatting's user; the key is the `api_key` attribute, and
 * a `read` key is refused anything but GET.
 *
 * A missing or bad key is a 401; each bad one counts towards the address's
 * throttle, and a throttled address gets 429 whatever it sends. Failures
 * are logged with the address, never the token.
 */
final readonly class ApiAuthMiddleware implements MiddlewareInterface
{
    public const string KEY = 'api_key';
    private const array READ_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private ApiKeyService $keys,
        private UserRepository $users,
        private FailedKeyThrottle $throttle,
        private AccessContext $access,
        private UserDisplayScope $scope,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $address = self::clientAddress($request);
        $wait = $this->throttle->blockedFor($address);
        if ($wait !== null) {
            throw new ApiProblem(429, 'too_many_failures', 'Too many failed API keys from this address. Try again later.', [], [
                'Retry-After' => (string) $wait,
            ]);
        }

        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            throw self::unauthorized('missing_key', 'Send an API key as "Authorization: Bearer lbk_…".');
        }

        $token = preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) === 1 ? $m[1] : '';
        $key = $token === '' ? null : $this->keys->verify($token);
        $user = $key === null ? null : $this->users->find($key->userId);
        if ($key === null || $user === null) {
            $reason = $token === '' || !ApiKeyService::isWellFormed($token) ? 'malformed' : 'unknown or revoked';
            $this->fail($request, $address, $reason);

            throw self::unauthorized('invalid_key', 'The API key is malformed, unknown or revoked.');
        }

        if (!in_array($request->getMethod(), self::READ_METHODS, true) && !$key->scope->canWrite()) {
            throw new ApiProblem(
                403,
                'insufficient_scope',
                'This key can only read. Create a "read and write" key to log entries.',
            );
        }

        $this->keys->touch($key);
        $this->access->apply($user);
        $request = $request
            ->withAttribute(CurrentUserMiddleware::ATTRIBUTE, $user)
            ->withAttribute(self::KEY, $key);

        // Formatted text (`display`, names) in the owner's language and units.
        return $this->scope->run($user, static fn (): ResponseInterface => $handler->handle($request));
    }

    public static function key(ServerRequestInterface $request): ApiKey
    {
        $key = $request->getAttribute(self::KEY);
        assert($key instanceof ApiKey, 'ApiAuthMiddleware must run first');

        return $key;
    }

    private function fail(ServerRequestInterface $request, string $address, string $reason): void
    {
        $blocked = $this->throttle->recordFailure($address);
        $this->logger->warning('API request with a {reason} key from {ip}', [
            'reason' => $reason,
            'ip' => $address,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
        ]);
        if ($blocked) {
            $this->logger->warning('API keys from {ip} are refused for {minutes} minutes after repeated failures', [
                'ip' => $address,
                'minutes' => intdiv(FailedKeyThrottle::BLOCK_SECONDS, 60),
            ]);
        }
    }

    private static function unauthorized(string $code, string $detail): ApiProblem
    {
        return new ApiProblem(401, $code, $detail, [], ['WWW-Authenticate' => 'Bearer realm="Logbook"']);
    }

    private static function clientAddress(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) && $address !== '' ? $address : 'unknown';
    }
}
