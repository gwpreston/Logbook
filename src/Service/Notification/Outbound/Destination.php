<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Outbound;

use Logbook\Domain\Ai\Location;

/**
 * Whether a channel may send to a URL (spec.md §7.11 *Where members'
 * channels may send*), and the address to connect to.
 */
final readonly class Destination
{
    /** The admin's setting does not allow this class of address. */
    public const string BLOCKED = 'blocked';
    /** A link-local address (169.254.0.0/16, fe80::/10): never for members. */
    public const string LINK_LOCAL = 'link_local';
    /** The name does not resolve, so it cannot be checked. */
    public const string UNRESOLVED = 'unresolved';
    /** Not an http(s) URL with a host. */
    public const string INVALID = 'invalid';

    private function __construct(
        public string $host,
        /** The widest class of its addresses (the card's badge); null when unknown. */
        public ?Location $location,
        /** The checked address the request connects to; null to let the client resolve. */
        public ?string $address,
        /** Why it is refused; null when allowed. */
        public ?string $refusal,
    ) {
    }

    public static function allowed(string $host, ?Location $location, ?string $address): self
    {
        return new self($host, $location, $address, null);
    }

    public static function refused(string $host, ?Location $location, string $reason): self
    {
        return new self($host, $location, null, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->refusal === null;
    }

    /** The policy itself refuses it (not a broken name): the card says *Blocked*. */
    public function isBlocked(): bool
    {
        return $this->refusal === self::BLOCKED || $this->refusal === self::LINK_LOCAL;
    }
}
