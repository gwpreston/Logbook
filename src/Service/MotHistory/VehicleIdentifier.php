<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

/**
 * Registrations and VINs as they may be sent (spec.md §7.38 *Lookup*):
 * spaces and dashes removed, upper-cased, and refused unless plainly one,
 * before anything is put in a URL.
 */
final class VehicleIdentifier
{
    public static function registration(?string $value): ?string
    {
        $plate = strtoupper((string) preg_replace('/[\s-]+/', '', (string) $value));

        return preg_match('/^[A-Z0-9]{1,8}$/', $plate) === 1 ? $plate : null;
    }

    public static function vin(?string $value): ?string
    {
        $vin = strtoupper((string) preg_replace('/[\s-]+/', '', (string) $value));

        // 17 characters since 1981; older vehicles have shorter frame numbers.
        return preg_match('/^[A-Z0-9]{5,20}$/', $vin) === 1 ? $vin : null;
    }
}
