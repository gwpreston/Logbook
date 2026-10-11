<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Property;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\SetupData;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserAdmin;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\Golden\Rng;
use Logbook\Tests\Support\Property\Figures;
use Logbook\Tests\Support\Property\HouseholdGenerator;
use Logbook\Tests\Support\Property\PropertyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The viewer's time zone (Phase 45.1 property, #386, #397): a fill-up at
 * 23:30 on the last day of a month counts in that month for whoever is
 * looking, by their own clock, whatever zone the server runs in. The owner
 * and a member who sees the costs are in different zones; each logs fills
 * at 23:30 on a month's last day, on 29 February 2024 and on 31 March 2024
 * (the day European summer time began), and each sees every fill in the
 * month of its local date in their own zone.
 */
#[Group('property')]
final class ViewersTimeZonePropertyTest extends PropertyTestCase
{
    private const array SERVER_ZONES = ['UTC', 'America/Los_Angeles', 'Asia/Kolkata', 'Pacific/Chatham'];

    #[DataProvider('seeds')]
    public function testEachViewerSeesAFillInTheMonthOfItsLocalDate(int $seed): void
    {
        $rng = new Rng($seed);
        $zones = HouseholdGenerator::ZONES;
        $ownerZone = $zones[$rng->between(0, count($zones) - 1)];
        do {
            $memberZone = $zones[$rng->between(0, count($zones) - 1)];
        } while ($memberZone === $ownerZone);
        $serverZone = self::SERVER_ZONES[$rng->between(0, count(self::SERVER_ZONES) - 1)];
        $month = (new DateTimeImmutable('2023-02-01'))->modify('+' . $rng->between(0, 38) . ' months');
        $days = [$month->format('Y-m-t'), '2024-02-29', '2024-03-31'];
        $why = "seed {$seed} (replay with PROPERTY_SEEDS={$seed}): owner in {$ownerZone}, member in {$memberZone}, "
            . "server in {$serverZone}, days " . implode(', ', $days);

        $app = $this->createApp();
        $this->pinClock($app, HouseholdGenerator::TODAY);
        $this->resetDatabase($app);
        $this->serverZone($serverZone);
        $owner = $this->service($app, AuthService::class)
            ->createInitialUser(new SetupData('owner', self::PASSWORD, 'Owner', self::preferences($ownerZone)));
        $member = $this->service($app, UserAdmin::class)
            ->addUser($owner, 'member', 'Member', 'member@example.test', false, self::preferences($memberZone));
        $vehicle = $this->service($app, VehicleService::class)->create($owner, new VehicleData(
            VehicleType::Car,
            'Make',
            'Model',
            FuelType::Petrol,
            currency: 'GBP',
            purchaseDate: LocalTime::parseDate('2023-01-01'),
            purchasePrice: '10000.00',
        ));
        $this->service($app, SharingService::class)->add($vehicle, 'member', ShareLevel::View, true, true);
        $this->service($app, OdometerService::class)
            ->create($vehicle, new OdometerReadingData('10000.000', new DateTimeImmutable('2023-01-01T12:00:00Z')));

        // One fill per day and person, each a different power of two pounds, so a month's total says which fills are in it.
        $fills = [];
        foreach ($days as $day) {
            foreach ([$ownerZone, $memberZone] as $zone) {
                $fills[] = (new DateTimeImmutable("{$day} 23:30", new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'));
            }
        }
        sort($fills);
        $km = 10000;
        foreach ($fills as $i => $at) {
            $km += 100;
            $this->service($app, FuelService::class)->create($vehicle, new FuelEntryData(
                $at,
                $km . '.000',
                Fuel::Petrol,
                '10.000',
                '1.000',
                (string) (2 ** $i) . '.00',
            ));
        }

        foreach (['owner' => [$owner, $ownerZone], 'member' => [$member, $memberZone]] as $who => [$user, $zone]) {
            $expected = [];
            foreach ($fills as $i => $at) {
                $local = $at->setTimezone(new DateTimeZone($zone))->format('Y-m');
                $expected[$local] = ($expected[$local] ?? 0) + 2 ** $i;
            }
            $seen = [];
            $report = (new Figures($app->getContainer()))->reports($user, [$vehicle])['all'];
            foreach ($report->currencies[0]->months as $total) {
                if (!$total->total->isZero()) {
                    $seen[$total->month->format('Y-m')] = intdiv($total->total->micros, 1000000);
                }
            }
            ksort($expected);
            ksort($seen);
            self::assertSame($expected, $seen, "{$who}'s months: {$why}");
        }
    }

    private static function preferences(string $zone): DisplayPreferences
    {
        return new DisplayPreferences(
            'en_GB',
            $zone,
            DistanceUnit::Mile,
            VolumeUnit::Litre,
            ConsumptionUnit::MpgUk,
            'GBP',
        );
    }
}
