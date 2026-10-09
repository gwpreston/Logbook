<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

/**
 * Whether DVSA's make is the vehicle's (spec.md §7.38 *Matching*, #336):
 * compared case-folded without spaces or dashes; one starting with the
 * other agrees, as do common short names. A blank make never disagrees.
 * The model is never compared: owners and DVSA name models differently.
 */
final class MakeMatch
{
    /** Names that are the same make, by their folded form. */
    private const array SAME = [
        ['vw', 'volkswagen'],
        ['merc', 'mercedes', 'mercedesbenz', 'benz'],
        ['landrover', 'rangerover'],
        ['vauxhall', 'opel'],
        ['mini', 'bmwmini', 'bmw'],
        ['alfa', 'alfaromeo'],
        ['chevy', 'chevrolet'],
        ['citroen', 'citroën'],
        ['skoda', 'škoda'],
        ['ds', 'dsautomobiles', 'citroen'],
    ];

    public static function agrees(?string $ours, ?string $dvsa): bool
    {
        $a = self::fold($ours);
        $b = self::fold($dvsa);
        if ($a === '' || $b === '' || str_starts_with($a, $b) || str_starts_with($b, $a)) {
            return true;
        }
        foreach (self::SAME as $names) {
            if (in_array($a, $names, true) && in_array($b, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether DVSA's model reads differently from the vehicle's, for the
     * page's note (never a refusal).
     */
    public static function modelDiffers(?string $ours, ?string $dvsa): bool
    {
        $a = self::fold($ours);
        $b = self::fold($dvsa);

        return $a !== '' && $b !== '' && !str_contains($b, $a) && !str_contains($a, $b);
    }

    private static function fold(?string $name): string
    {
        return mb_strtolower((string) preg_replace('/[\s\-.]+/u', '', (string) $name));
    }
}
