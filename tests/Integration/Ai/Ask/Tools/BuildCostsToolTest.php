<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AskTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * `costs` and `cost_per_distance` with build spend (Phase 50.1, spec.md
 * §7.7): a modification is in the total but never a running cost, so the
 * monthly average and the rate leave it out and the result says which
 * total each figure comes from (ai-evaluator cases ask-costs-01,
 * ask-costs-03 and ask-cpd-01).
 */
final class BuildCostsToolTest extends AskTestCase
{
    use ToolsATesting;

    public function testTheTotalHasTheBuildAndTheAverageIsRunningCostsOnly(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_PARTS' => 'true']);
        $golf = $this->golf($app, $owner);

        $costs = $this->data($app, $owner, 'costs', ['vehicles' => [$golf->id], 'period' => 'last_year']);
        self::assertSame('4020.00', $costs->get('currencies', 0, 'total', 'amount'));
        self::assertSame('2780.00', $costs->get('currencies', 0, 'running_total', 'amount'));
        self::assertSame('1240.00', $costs->get('currencies', 0, 'build_total', 'amount'));
        self::assertSame('231.67', $costs->get('currencies', 0, 'average_per_month', 'amount'), '2780 over 12 months');
        self::assertIsString($costs->get('currencies', 0, 'note'));

        $build = $this->data($app, $owner, 'costs', ['vehicles' => [$golf->id], 'period' => 'last_year', 'category' => 'build']);
        self::assertSame('1240.00', $build->get('currencies', 0, 'total', 'amount'));
        self::assertNull($build->get('currencies', 0, 'average_per_month'), 'no £0.00 a month for modifications');

        $fuel = $this->data($app, $owner, 'costs', ['vehicles' => [$golf->id], 'period' => 'last_year', 'category' => 'fuel']);
        self::assertSame('1500.00', $fuel->get('currencies', 0, 'total', 'amount'));
        self::assertSame('125.00', $fuel->get('currencies', 0, 'average_per_month', 'amount'));
        self::assertNull($fuel->get('currencies', 0, 'running_total'), 'no build, no second total');
    }

    public function testTheRateDividesTheTotalItShows(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_PARTS' => 'true']);
        $golf = $this->golf($app, $owner);

        $rate = $this->data($app, $owner, 'cost_per_distance', ['vehicles' => [$golf->id], 'period' => 'last_year']);
        self::assertSame('2780.00', $rate->get('currencies', 0, 'all_vehicles', 'total', 'amount'));
        self::assertSame('2780.00', $rate->get('currencies', 0, 'vehicles', 0, 'total', 'amount'));
        self::assertSame('0.2780', $rate->get('currencies', 0, 'all_vehicles', 'cost_per_km'), '2780 over 10,000 km');
        // #441: the build spend is given beside it as its own figure, so an answer can say both.
        self::assertSame('1240.00', $rate->get('currencies', 0, 'all_vehicles', 'build_total', 'amount'));
        self::assertSame('1240.00', $rate->get('currencies', 0, 'vehicles', 0, 'build_total', 'amount'));
        self::assertStringContainsString('build_total', $rate->string('note'));
    }

    public function testTheDescriptionsSayModificationsAreNotRunningCosts(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_PARTS' => 'true']);
        $descriptions = [];
        foreach ($this->service($app, ToolRegistry::class)->available($owner) as $tool) {
            $descriptions[$tool->name()] = $tool->definition()->description;
        }

        self::assertStringContainsString('build for modifications', $descriptions['costs']);
        self::assertStringContainsString('not running costs', $descriptions['costs']);
        self::assertStringContainsString('Modifications are not running costs', $descriptions['cost_per_distance']);
    }

    /**
     * 2025: £1,500 of fuel over 10,000 km, £500 insurance, a £780 clutch and
     * £1,240 of coilovers.
     *
     * @param App<ContainerInterface> $app
     */
    private function golf(App $app, User $owner): Vehicle
    {
        $golf = $this->service($app, VehicleService::class)->create($owner, new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            registration: 'GO19 ABC',
            purchaseDate: new DateTimeImmutable('2024-01-01'),
            purchasePrice: '15000',
            currency: 'GBP',
        ));
        $this->reading($app, $golf, '1000', '2025-01-01T09:00:00Z');
        $this->fillUp($app, $golf, '2025-06-01T09:00:00Z', '6000', '500', '700.00');
        $this->fillUp($app, $golf, '2025-12-31T09:00:00Z', '11000', '500', '800.00');
        $this->document($app, $golf, ComplianceType::Insurance, '2025-03-01', null, '500.00');
        $this->maintenance($app, $golf, '2025-11-20', 'Clutch replaced', '780.00');
        $this->service($app, ModificationService::class)->create($golf, new ModificationData(
            'Coilovers',
            ModificationCategory::Suspension,
            new DateTimeImmutable('2025-05-10'),
            cost: '1240.000',
        ), new DateTimeZone('Europe/London'));

        return $golf;
    }
}
