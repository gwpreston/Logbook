<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use DateInterval;
use Logbook\Repository\SessionRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Session\Session;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Database-backed sessions over a PSR-7 cookie (spec.md §7.9).
 *
 * Lazy: a visitor gets a cookie and a row only once something is stored, so
 * health checks and anonymous page views create nothing. The cookie holds a
 * random 256-bit token; the database holds only its HMAC. A regenerated
 * session (sign-in, sign-out) gets a fresh token and the old row is deleted.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'session';
    public const string COOKIE = 'logbook_session';
    /** Idle lifetime: a session unused for this long is gone. */
    public const int LIFETIME_SECONDS = 30 * 24 * 3600;
    /** Refresh last-activity (and the cookie's expiry) at most this often. */
    private const int TOUCH_SECONDS = 300;

    private string $cookiePath;

    public function __construct(
        private SessionRepository $sessions,
        private ClockInterface $clock,
        private AppSettings $settings,
    ) {
        $this->cookiePath = $settings->basePath === '' ? '/' : $settings->basePath;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->load($request);

        $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $session));

        return $this->commit($session, $response);
    }

    private function load(ServerRequestInterface $request): Session
    {
        $token = $request->getCookieParams()[self::COOKIE] ?? null;
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return Session::start();
        }

        $activeSince = $this->clock->now()->sub(new DateInterval('PT' . self::LIFETIME_SECONDS . 'S'));
        $stored = $this->sessions->findActive($this->id($token), $activeSince);

        return $stored === null
            ? Session::start()
            : Session::resume($token, $stored['data'], $stored['last_activity_at']);
    }

    private function commit(Session $session, ResponseInterface $response): ResponseInterface
    {
        $now = $this->clock->now();
        $oldToken = $session->token();

        if ($session->isEmpty()) {
            if ($oldToken === null) {
                return $response;
            }
            $this->sessions->delete($this->id($oldToken));

            return $response->withAddedHeader('Set-Cookie', $this->cookie('', 0));
        }

        if ($oldToken === null || $session->wasRegenerated()) {
            if ($oldToken !== null) {
                $this->sessions->delete($this->id($oldToken));
            }
            // Creating sessions is rare (sign-in, first form view): sweep expired ones then.
            $this->sessions->deleteInactiveSince($now->sub(new DateInterval('PT' . self::LIFETIME_SECONDS . 'S')));

            $token = self::newToken();
            $this->sessions->insert($this->id($token), $session->userId(), $session->all(), $now);

            return $response->withAddedHeader('Set-Cookie', $this->cookie($token, self::LIFETIME_SECONDS));
        }

        $lastActivity = $session->lastActivity();
        $stale = $lastActivity === null || $now->getTimestamp() - $lastActivity->getTimestamp() >= self::TOUCH_SECONDS;
        if (!$session->isDirty() && !$stale) {
            return $response;
        }

        $this->sessions->update($this->id($oldToken), $session->userId(), $session->all(), $now);

        return $response->withAddedHeader('Set-Cookie', $this->cookie($oldToken, self::LIFETIME_SECONDS));
    }

    private function id(string $token): string
    {
        return hash_hmac('sha256', $token, $this->settings->sessionSecret);
    }

    private function cookie(string $value, int $maxAge): string
    {
        $expires = $maxAge > 0 ? $this->clock->now()->getTimestamp() + $maxAge : 0;

        $attributes = [
            self::COOKIE . '=' . $value,
            'Path=' . $this->cookiePath,
            'Max-Age=' . $maxAge,
            'Expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($this->settings->sessionSecure) {
            $attributes[] = 'Secure';
        }

        return implode('; ', $attributes);
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
