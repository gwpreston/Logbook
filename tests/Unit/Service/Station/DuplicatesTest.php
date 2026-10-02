<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Station;

use DateTimeImmutable;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Service\Station\DuplicatePair;
use Logbook\Service\Station\DuplicateReason;
use Logbook\Service\Station\Duplicates;
use PHPUnit\Framework\TestCase;

/**
 * The duplicates view finds its three kinds (spec.md §7.33 *Duplicates*).
 */
final class DuplicatesTest extends TestCase
{
    private static function station(
        int $id,
        string $name,
        ?string $brand = null,
        ?string $lat = null,
        ?string $lon = null,
        ?int $mergedInto = null,
    ): Station {
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new Station($id, new StationData($name, $brand, latitude: $lat, longitude: $lon), $now, $now, 1, $mergedInto);
    }

    /**
     * @param list<DuplicatePair> $pairs
     * @return array<string, list<string>> "first|second" => reasons
     */
    private static function flatten(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $pair) {
            $out[$pair->first->data->name . '|' . $pair->second->data->name] = array_map(
                static fn (DuplicateReason $reason): string => $reason->value,
                $pair->reasons,
            );
        }

        return $out;
    }

    public function testTheThreeKinds(): void
    {
        $pairs = Duplicates::find([
            self::station(1, 'Tesco Antrim', 'Tesco'),
            self::station(2, 'Antrim', 'tesco'),
            self::station(3, 'Maxol Ballymena'),
            self::station(4, 'Maxol Balymena'),
            self::station(5, 'Applegreen M2', null, '54.700000', '-6.200000'),
            self::station(6, 'Applegreen Motorway', null, '54.701000', '-6.200500'),
            self::station(7, 'Far away', null, '54.800000', '-6.200000'),
        ]);

        self::assertSame([
            'Applegreen M2|Applegreen Motorway' => ['close'],
            'Maxol Ballymena|Maxol Balymena' => ['one_edit'],
            'Tesco Antrim|Antrim' => ['same_brand'],
        ], self::flatten($pairs));
        $close = $pairs[0];
        self::assertNotNull($close->km);
        self::assertLessThan(0.150, $close->km);
    }

    public function testMergedStationsAndUnrelatedNamesAreLeftOut(): void
    {
        self::assertSame([], Duplicates::find([
            self::station(1, 'Shell Larne'),
            self::station(2, 'Shell Larme', mergedInto: 1),
            self::station(3, 'Esso Larne', 'Esso'),
            self::station(4, 'Esso Carrick', 'Esso'),
            self::station(5, 'BP', null, '54.0', '-6.0'),
            self::station(6, 'Texaco', null, '54.002', '-6.0'),
        ]));
    }

    public function testEditDistanceCountsCharactersNotBytes(): void
    {
        self::assertSame(['Café du Nord|Cafe du Nord' => ['one_edit']], self::flatten(Duplicates::find([
            self::station(1, 'Café du Nord'),
            self::station(2, 'Cafe du Nord'),
        ])));
    }
}
