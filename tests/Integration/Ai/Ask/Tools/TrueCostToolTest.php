<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Ai\Ask\GroundingCheck;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\TrueCostWording;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\JsonDoc;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * `true_cost` (docs/phases/phase-32.md, spec.md §7.35): the service's
 * figures and causes, worded by Logbook, only with ViewCosts, and an
 * answer built from them passes the grounding check.
 */
final class TrueCostToolTest extends AskTestCase
{
    use ToolsATesting;

    public function testTheSameFiguresAsTheService(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->golf($app);

        $data = $this->data($app, $owner, 'true_cost', ['vehicles' => [$golf->id], 'period' => 'since_bought']);

        $today = LocalTime::parseDate('2026-10-15');
        self::assertNotNull($today);
        $expected = $this->service($app, TrueCostService::class)->forVehicle($owner, $golf, $today);
        self::assertNotNull($expected);
        self::assertNotNull($expected->sinceBought->perKm);
        self::assertSame($expected->sinceBought->perKm, $data->get('vehicles', 0, 'per_distance', 'per_km'));
        self::assertSame('since_bought', $data->get('period', 'key'));
        self::assertSame('fuel', $data->get('vehicles', 0, 'parts', 0, 'part'));
    }

    public function testByYearCarriesWhatChangedAsSentences(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->golf($app);

        $run = $this->call($app, $owner, 'true_cost', ['vehicles' => [$golf->id], 'by_year' => true]);
        self::assertNotNull($run->result);
        $data = new JsonDoc($run->result->data);

        self::assertSame(2025, $data->get('vehicles', 0, 'what_changed', 0, 'year'));
        $lines = $data->get('vehicles', 0, 'what_changed', 0, 'lines');
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $sentences = array_map(
            static fn (mixed $line): string => is_array($line) && is_string($line['sentence'] ?? null) ? $line['sentence'] : '',
            $lines,
        );
        // The same £500 of insurance over 5,000 km instead of 10,000 km: +£0.05 a km, £0.08 a mile.
        self::assertContains('Insurance, tax and MOT +£0.08/mi, because you drove 3,107 mi less', $sentences);

        // An answer quoting the sentences is grounded in the result.
        $total = $data->get('vehicles', 0, 'what_changed', 0, 'total', 'per_km');
        self::assertIsString($total);
        $answer = 'Your Golf costs more per mile because: ' . implode('; ', $sentences) . '. '
            . $this->service($app, TrueCostWording::class)->signed($total, 'GBP');
        self::assertSame([], (new GroundingCheck())->ungrounded($answer, [$run->content()], 'en_GB'));
    }

    public function testWithoutViewCostsNothingIsShown(): void
    {
        [$app, $owner, $access] = $this->policyApp();
        $golf = $this->golf($app);
        $access->except($golf, VehicleAbility::ViewCosts);

        $run = $this->call($app, $owner, 'true_cost', ['vehicles' => [$golf->id], 'by_year' => true]);
        $data = new JsonDoc($run->result?->data);

        self::assertSame([], $data->get('vehicles'));
        self::assertSame($golf->id, $data->get('costs_not_shared', 0, 'id'));
        self::assertSame([], $run->result?->figures);
    }

    public function testTheSchemaIsValid(): void
    {
        [$app] = $this->askApp();

        $this->assertSchemaFits($app, 'true_cost', ['vehicles' => [1], 'period' => 'last_12_months', 'by_year' => true]);
    }

    /**
     * Bought on 1 January 2024 for £15,000. 2024: 10,000 km; 2025: 5,000 km;
     * £500 of insurance each year (no expiry, so on its date).
     *
     * @param App<ContainerInterface> $app
     */
    private function golf(App $app): Vehicle
    {
        $golf = $this->service($app, VehicleService::class)->create(
            $this->owner($app),
            new VehicleData(
                VehicleType::Car,
                'Volkswagen',
                'Golf',
                FuelType::Petrol,
                registration: 'GO19 ABC',
                purchaseDate: LocalTime::parseDate('2024-01-01'),
                purchasePrice: '15000.00',
            ),
        );
        $this->reading($app, $golf, '1000', '2024-01-01T09:00:00Z');
        $this->fillUp($app, $golf, '2024-06-01T09:00:00Z', '6000', '350', '525.00');
        $this->fillUp($app, $golf, '2024-12-31T09:00:00Z', '11000', '350', '525.00');
        $this->fillUp($app, $golf, '2025-06-01T09:00:00Z', '13500', '170', '272.00');
        $this->fillUp($app, $golf, '2025-12-31T09:00:00Z', '16000', '170', '272.00');
        $this->document($app, $golf, ComplianceType::Insurance, '2024-03-01', null, '500.00');
        $this->document($app, $golf, ComplianceType::Insurance, '2025-03-01', null, '500.00');

        return $golf;
    }
}
