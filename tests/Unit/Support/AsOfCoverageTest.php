<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use Logbook\Service\Finance\Equity;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Support\AsOf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * The inventory of spec.md §8 (*Show the working*): each result that carries
 * a figure resting on a dated input also carries its AsOf. Adding a figure
 * to the inventory without one fails here; list it when it is built.
 */
final class AsOfCoverageTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string}>
     */
    public static function inventory(): array
    {
        return [
            'depreciation (valuation)' => [Depreciation::class, 'asOf'],
            'equity (valuation)' => [Equity::class, 'asOf'],
            'coming up cost (last time)' => [ForecastItem::class, 'costAsOf'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('inventory')]
    public function testEveryInventoryResultCarriesItsAsOf(string $class, string $property): void
    {
        self::assertTrue(property_exists($class, $property), $class . ' must carry ' . $property);
        $type = (new ReflectionProperty($class, $property))->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(AsOf::class, $type->getName());
    }
}
