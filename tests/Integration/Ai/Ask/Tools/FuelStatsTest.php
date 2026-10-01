<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Tests\Support\AskTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * `fuel_stats` (spec.md §7.26): fill-ups in a period, by grade, as the fuel
 * page counts them, in the user's units and language.
 */
final class FuelStatsTest extends AskTestCase
{
    use ToolsATesting;

    public function testThePeriodsFillUpsWithEconomySpendAndPrice(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->fuelled($app);

        $data = $this->data($app, $owner, 'fuel_stats', ['vehicle' => $golf->id, 'period' => 'last_year']);

        $kind = $data->doc('vehicles', 0, 'kinds', 0);
        self::assertSame('liquid', $kind->get('kind'));
        self::assertSame(3, $kind->get('fill_ups'));
        self::assertSame('125', rtrim(rtrim($kind->string('volume', 'litres'), '0'), '.'));
        self::assertSame('175.00', $kind->get('spend', 'amount'));
        self::assertSame('£175.00', $kind->get('spend', 'display'));
        self::assertSame('1.400', $kind->get('average_price_per_unit', 'amount'));
        // Three tanks closed within 2025: 1,800 km on 125 litres.
        self::assertSame('1800', rtrim(rtrim($kind->string('economy', 'distance_km'), '0'), '.'));
        self::assertSame(
            $this->shown($app, $owner, static fn (DisplayFormatter $f): string => $f->economy('1800', '125', false)),
            $kind->get('economy', 'display'),
        );
        self::assertStringContainsString('mpg', $kind->get('economy', 'display'));
    }

    public function testByGradeAndAGradeFilter(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->fillUp($app, $golf, '2025-02-01T09:00:00Z', '10000', '40', '56.00', grade: FuelGrade::E10_95);
        $this->fillUp($app, $golf, '2025-03-01T09:00:00Z', '10600', '42', '63.00', grade: FuelGrade::E5_97);

        $data = $this->data($app, $owner, 'fuel_stats', ['vehicle' => $golf->id, 'period' => 'last_year']);
        $grades = $data->column('grade', 'vehicles', 0, 'kinds', 0, 'by_grade');
        self::assertEqualsCanonicalizing(['e10_95', 'e5_97'], $grades);

        $only = $this->data($app, $owner, 'fuel_stats', ['vehicle' => $golf->id, 'period' => 'last_year', 'grade' => 'e5_97']);
        self::assertSame(1, $only->get('vehicles', 0, 'kinds', 0, 'fill_ups'));
        self::assertSame('63.00', $only->get('vehicles', 0, 'kinds', 0, 'spend', 'amount'));
    }

    public function testEveryVehicleWhenNoneIsNamed(): void
    {
        [$app, $owner] = $this->askApp();
        $this->fuelled($app);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->fillUp($app, $polo, '2025-05-01T09:00:00Z', '5000', '30', '42.00');

        $data = $this->data($app, $owner, 'fuel_stats', ['period' => 'last_year']);

        self::assertSame(2, $data->get('count'));
        $run = $this->call($app, $owner, 'fuel_stats', ['period' => 'last_year']);
        self::assertSame('/garage', $run->result?->link);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function preferences(): iterable
    {
        yield 'kilometres' => ['en_GB', 'metric', 'L/100'];
        yield 'UK' => ['en_GB', 'uk', 'mpg'];
        yield 'US' => ['en_US', 'us', 'mpg'];
        yield 'German' => ['de_DE', 'metric', '100'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('preferences')]
    public function testDisplayStringsFollowTheUsersUnitsAndLanguage(string $locale, string $preset, string $unit): void
    {
        [$app, $owner] = $this->askApp(preferences: self::prefs($locale, $preset));
        $golf = $this->fuelled($app);

        $kind = $this->data($app, $owner, 'fuel_stats', ['vehicle' => $golf->id, 'period' => 'last_year'])
            ->doc('vehicles', 0, 'kinds', 0);

        $economy = $this->shown($app, $owner, static fn (DisplayFormatter $f): string => $f->economy('1800', '125', false));
        $volume = $this->shown($app, $owner, static fn (DisplayFormatter $f): string => $f->volume('125.000'));
        self::assertSame($economy, $kind->get('economy', 'display'));
        self::assertSame($volume, $kind->get('volume', 'display'));
        self::assertStringContainsString($unit, $economy);
        if ($locale === 'de_DE') {
            self::assertMatchesRegularExpression('/\d,\d/', $economy, 'a decimal comma');
            self::assertStringContainsString('175,00', $kind->string('spend', 'display'));
        }
        if ($preset === 'us') {
            self::assertStringContainsString('gal', $volume);
        }
    }

    public function testWithoutViewCostsSpendAndPricesAreLeftOut(): void
    {
        [$app, $owner, $access] = $this->policyApp();
        $golf = $this->fuelled($app);
        $access->except($golf, VehicleAbility::ViewCosts);

        $kind = $this->data($app, $owner, 'fuel_stats', ['vehicle' => $golf->id, 'period' => 'last_year'])
            ->doc('vehicles', 0, 'kinds', 0);

        self::assertFalse($kind->has('spend'));
        self::assertFalse($kind->has('average_price_per_unit'));
        self::assertNotNull($kind->get('economy'));
        $json = (string) $this->call($app, $owner, 'fuel_stats', ['vehicle' => $golf->id, 'period' => 'last_year'])->content();
        self::assertStringNotContainsString('175', $json);
    }

    public function testAnotherUsersVehicleIsNotFound(): void
    {
        [$app] = $this->askApp();
        $golf = $this->fuelled($app);
        $member = $this->createMember($app);

        $run = $this->call($app, $member, 'fuel_stats', ['vehicle' => $golf->id]);

        self::assertSame(ToolKit::NOT_FOUND, $run->error);
        self::assertNull($run->result);
    }

    public function testNotOfferedWithTheFuelModuleOff(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_FUEL' => 'false']);

        self::assertNotContains('fuel_stats', $this->offered($app, $owner));
        self::assertNotNull($this->call($app, $owner, 'fuel_stats', [])->error);
    }

    public function testTheSchemaIsValid(): void
    {
        [$app] = $this->askApp();

        $this->assertSchemaFits($app, 'fuel_stats', ['vehicle' => 1, 'period' => 'last_year', 'grade' => 'e10_95']);
    }

    /**
     * Four full fill-ups: one before 2025, three in it, so three tanks
     * close within 2025 (600 km on 40, 40 and 45 litres).
     *
     * @param App<ContainerInterface> $app
     */
    private function fuelled(App $app): Vehicle
    {
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf', fuel: FuelType::Petrol);
        $this->fillUp($app, $golf, '2024-12-01T09:00:00Z', '9400', '38', '53.20');
        $this->fillUp($app, $golf, '2025-01-10T09:00:00Z', '10000', '40', '56.00');
        $this->fillUp($app, $golf, '2025-03-01T09:00:00Z', '10600', '40', '56.00');
        $this->fillUp($app, $golf, '2025-05-01T09:00:00Z', '11200', '45', '63.00');

        return $golf;
    }
}
