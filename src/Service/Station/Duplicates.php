<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationName;
use Logbook\Support\Geo\Haversine;

/**
 * Pairs of stations that may be one forecourt (spec.md §7.33
 * *Duplicates*): the same brand and name apart from the brand, normalised
 * names one edit apart, or positions within 150 m. Pure; merged stations
 * are left out.
 */
final class Duplicates
{
    public const float CLOSE_KM = 0.150;

    /**
     * @param list<Station> $stations
     * @return list<DuplicatePair> by the first station's name, then the second's
     */
    public static function find(array $stations): array
    {
        $active = array_values(array_filter($stations, static fn (Station $station): bool => !$station->isMerged()));
        usort($active, static fn (Station $a, Station $b): int => $a->id <=> $b->id);
        $names = array_map(static fn (Station $station): string => StationName::normalise($station->data->name), $active);

        $pairs = [];
        $count = count($active);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $active[$i];
                $b = $active[$j];
                $reasons = [];
                if (self::sameBrand($a, $b)) {
                    $reasons[] = DuplicateReason::SameBrand;
                }
                if (self::oneEdit($names[$i], $names[$j])) {
                    $reasons[] = DuplicateReason::OneEdit;
                }
                $km = self::km($a, $b);
                if ($km !== null && $km <= self::CLOSE_KM) {
                    $reasons[] = DuplicateReason::Close;
                }
                if ($reasons !== []) {
                    $pairs[] = new DuplicatePair($a, $b, $reasons, $km);
                }
            }
        }
        usort($pairs, static fn (DuplicatePair $x, DuplicatePair $y): int => [
            StationName::normalise($x->first->data->name),
            StationName::normalise($x->second->data->name),
        ] <=> [
            StationName::normalise($y->first->data->name),
            StationName::normalise($y->second->data->name),
        ]);

        return $pairs;
    }

    /**
     * The same brand, and the same name once the brand is taken out of
     * both ("Tesco Antrim" and "Antrim", both Tesco).
     */
    private static function sameBrand(Station $a, Station $b): bool
    {
        $brand = StationName::normalise($a->data->brand ?? '');
        if ($brand === '' || $brand !== StationName::normalise($b->data->brand ?? '')) {
            return false;
        }

        return self::withoutBrand($a->data->name, $brand) === self::withoutBrand($b->data->name, $brand);
    }

    private static function withoutBrand(string $name, string $brand): string
    {
        $normalised = StationName::normalise($name);

        return StationName::tidy(str_replace($brand, ' ', $normalised));
    }

    private static function oneEdit(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (abs(mb_strlen($a) - mb_strlen($b)) > 1) {
            return false;
        }

        return self::levenshtein($a, $b) === 1;
    }

    /**
     * Character (not byte) edit distance, so "Café" and "Cafe" are one edit.
     */
    private static function levenshtein(string $a, string $b): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $previous = range(0, count($y));
        foreach ($x as $i => $charA) {
            $current = [$i + 1];
            foreach ($y as $j => $charB) {
                $current[] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($charA === $charB ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return $previous[count($y)];
    }

    private static function km(Station $a, Station $b): ?float
    {
        if (!$a->data->hasPosition() || !$b->data->hasPosition()) {
            return null;
        }

        return Haversine::km(
            (float) $a->data->latitude,
            (float) $a->data->longitude,
            (float) $b->data->latitude,
            (float) $b->data->longitude,
        );
    }
}
