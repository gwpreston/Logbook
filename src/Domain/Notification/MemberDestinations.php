<?php

declare(strict_types=1);

namespace Logbook\Domain\Notification;

use Logbook\Domain\Ai\Location;

/**
 * Where members' channels may send (spec.md §7.11 *Where members' channels
 * may send*, Phase 36.2): an admin's choice on Settings → Delivery.
 * Link-local addresses are refused whatever it says.
 */
enum MemberDestinations: string
{
    case Internet = 'internet';
    case Network = 'network';
    case Server = 'server';

    /** The default (#229): a household's own ntfy or Gotify keeps working. */
    public const self DEFAULT = self::Network;

    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::DEFAULT) : self::DEFAULT;
    }

    public function allows(Location $location): bool
    {
        return match ($this) {
            self::Internet => $location === Location::Internet,
            self::Network => $location !== Location::Server,
            self::Server => true,
        };
    }

    public function labelKey(): string
    {
        return 'delivery.destinations.' . $this->value;
    }
}
