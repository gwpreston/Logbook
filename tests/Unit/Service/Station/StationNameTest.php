<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Station;

use Logbook\Domain\Station\StationName;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Station names are matched normalised (spec.md §7.33), in PHP, the same
 * way as the upgrade migration does.
 */
final class StationNameTest extends TestCase
{
    public function testTrimsCollapsesAndFoldsCase(): void
    {
        self::assertSame('tesco antrim', StationName::normalise("  Tesco \t Antrim\n"));
        self::assertSame('Tesco Antrim', StationName::tidy("  Tesco \t Antrim\n"));
        self::assertSame('aral münchen', StationName::normalise('ARAL  MÜNCHEN'));
        self::assertSame('', StationName::normalise('   '));
    }

    public function testTheUpgradeMigrationNormalisesAlike(): void
    {
        require_once dirname(__DIR__, 4) . '/db/migrations/20261027100100_link_fuel_stations.php';
        $method = (new ReflectionClass(\LinkFuelStations::class))->getMethod('normalise');
        foreach (["  Tesco \t Antrim\n", 'ARAL  MÜNCHEN', 'Shell', '   ', 'Café du Nord'] as $text) {
            self::assertSame(StationName::normalise($text), $method->invoke(null, $text), $text);
        }
    }
}
