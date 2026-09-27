<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Logbook\Domain\User\User;
use Logbook\Middleware\CurrentUserMiddleware;
use Logbook\Middleware\SessionMiddleware;
use Logbook\Support\Session\Session;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed access to the per-request attributes set by middleware.
 */
final class RequestContext
{
    public static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        if (!$session instanceof Session) {
            throw new LogicException('No session on the request: is SessionMiddleware registered?');
        }

        return $session;
    }

    public static function user(ServerRequestInterface $request): ?User
    {
        $user = $request->getAttribute(CurrentUserMiddleware::ATTRIBUTE);

        return $user instanceof User ? $user : null;
    }

    /**
     * The signed-in user on a route behind AuthGuardMiddleware.
     */
    public static function requireUser(ServerRequestInterface $request): User
    {
        return self::user($request) ?? throw new LogicException('Route requires AuthGuardMiddleware.');
    }

    /**
     * The locale resolved by LocaleMiddleware.
     */
    public static function locale(ServerRequestInterface $request): string
    {
        $locale = $request->getAttribute('locale');

        return is_string($locale) ? $locale : 'en';
    }

    /**
     * The parsed form body as an array (empty for anything else).
     *
     * @return array<array-key, mixed>
     */
    public static function form(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * Submitted string fields, for re-filling a form after a validation
     * error. Password fields are never echoed back.
     *
     * @return array<string, string>
     */
    public static function formValues(ServerRequestInterface $request): array
    {
        $values = [];
        foreach (self::form($request) as $key => $value) {
            if (is_string($key) && is_string($value) && !str_contains($key, 'password')) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
