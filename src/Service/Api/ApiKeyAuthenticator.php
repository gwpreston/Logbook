<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Repository\UserRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Support\Api\FailedKeyThrottle;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * API keys at the door, for the REST API (spec.md §7.20) and the MCP
 * endpoint (§7.28): `Authorization: Bearer lbk_…` is the only way in.
 *
 * A missing or bad key is refused with 401; each bad one counts towards the
 * address's throttle, and a throttled address is refused with 429 whatever
 * it sends. Failures are logged with the address, never the token. `as()`
 * records a key's use and runs the work as its user, with the access policy
 * and the display preferences (language, units) applied, so a key never
 * sees more than its user.
 */
final readonly class ApiKeyAuthenticator
{
    public function __construct(
        private ApiKeyService $keys,
        private UserRepository $users,
        private FailedKeyThrottle $throttle,
        private AccessContext $access,
        private UserDisplayScope $scope,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws KeyRefused
     */
    public function authenticate(ServerRequestInterface $request): KeyHolder
    {
        $address = self::clientAddress($request);
        $wait = $this->throttle->blockedFor($address);
        if ($wait !== null) {
            throw new KeyRefused(429, 'too_many_failures', 'Too many failed API keys from this address. Try again later.', [
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
        if ($key === null || $user === null || !$user->isActive()) {
            $reason = $token === '' || !ApiKeyService::isWellFormed($token) ? 'malformed' : 'unknown or revoked';
            $this->fail($request, $address, $reason);

            throw self::unauthorized('invalid_key', 'The API key is malformed, unknown or revoked.');
        }

        return new KeyHolder($user, $key);
    }

    /**
     * Record the key's use and run $work as its user: their access and
     * display preferences.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function as(KeyHolder $holder, callable $work): mixed
    {
        $this->keys->touch($holder->key);
        $this->access->apply($holder->user);

        return $this->scope->run($holder->user, $work);
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

    private static function unauthorized(string $code, string $detail): KeyRefused
    {
        return new KeyRefused(401, $code, $detail, ['WWW-Authenticate' => 'Bearer realm="Logbook"']);
    }

    private static function clientAddress(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) && $address !== '' ? $address : 'unknown';
    }
}
