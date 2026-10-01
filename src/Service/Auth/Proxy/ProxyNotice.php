<?php

declare(strict_types=1);

namespace Logbook\Service\Auth\Proxy;

/**
 * What the page should say about header sign-in on this request (spec.md
 * §7.9 *Sign-in page*, *Linking while signed in*); a request attribute
 * the templates read as `proxy`.
 */
final readonly class ProxyNotice
{
    /** A listed proxy sent no user. */
    public const string MISSING = 'missing';
    /** A proxy account nobody may use. */
    public const string NOT_LINKED = 'not_linked';
    /** A proxy account whose user may not sign in (disabled, or creation failed). */
    public const string REFUSED = 'refused';
    /** A signed-in user may link this proxy account. */
    public const string LINK_OFFER = 'link_offer';

    private function __construct(
        public string $kind,
        /** The proxy account's username (or subject), for the message. */
        public string $name = '',
    ) {
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }

    public static function notLinked(string $name): self
    {
        return new self(self::NOT_LINKED, $name);
    }

    public static function refused(string $name): self
    {
        return new self(self::REFUSED, $name);
    }

    public static function linkOffer(string $name): self
    {
        return new self(self::LINK_OFFER, $name);
    }
}
