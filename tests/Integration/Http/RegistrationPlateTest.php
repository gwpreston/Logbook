<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Registration plates (spec.md §8 *Registration plate*, Phase 34.1): drawn
 * on the garage, the dashboard, the vehicle header and the pickers in the
 * owner's style; nothing for a blank registration; text everywhere else.
 */
final class RegistrationPlateTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-10-06T10:00:00Z';
    private const string GB_PLATE = '<span class="plate plate--md plate--gb">'
        . '<span class="plate__band" aria-hidden="true">UK</span>'
        . '<span class="plate__text">AB12 CDE</span></span>';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['APP_URL' => 'http://localhost:8080']);
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
    }

    public function testAUkOwnersCarShowsTheUkPlateWhereverItIdentifiesTheCar(): void
    {
        $golf = $this->car($this->owner($this->app), ' ab12   cde ');

        $garage = self::body($this->browser->get('/garage'));
        self::assertStringContainsString(self::GB_PLATE, $garage, 'garage card: md, trimmed, upper case, one space');

        $dashboard = self::body($this->browser->get('/'));
        self::assertStringContainsString(
            '<span class="vehicle-tile__plate"><span class="plate plate--sm plate--gb">',
            $dashboard,
            'the tile: sm, over the photo',
        );

        $pinned = self::body($this->browser->get('/?vehicle=' . $golf->id));
        self::assertStringContainsString(self::GB_PLATE, $pinned, 'the pinned card');

        foreach (['', '/fuel', '/history'] as $tab) {
            $page = self::body($this->browser->get('/vehicles/' . $golf->id . $tab));
            self::assertStringContainsString(self::GB_PLATE, $page, 'the header on ' . ($tab ?: 'overview'));
        }

        $this->car($this->owner($this->app), 'XY34 ZZZ'); // with one vehicle the picker is skipped
        $picker = self::body($this->browser->get('/fuel/new'));
        self::assertStringContainsString('<span class="plate plate--sm plate--gb">', $picker, 'the one-tap picker');

        $delete = self::body($this->browser->get('/vehicles/' . $golf->id . '/delete'));
        self::assertStringContainsString('<span class="plate plate--sm plate--gb">', $delete);
    }

    public function testAnotherRegionGetsTheNeutralPlateWithNoBand(): void
    {
        $owner = $this->createMember($this->app, 'yank', self::us());
        $mustang = $this->car($owner, 'MUST4NG');
        $theirs = $this->browserFor($this->app, 'yank');

        $garage = self::body($theirs->get('/garage'));
        self::assertStringContainsString(
            '<span class="plate plate--md plate--neutral"><span class="plate__text">MUST4NG</span></span>',
            $garage,
        );
        self::assertStringNotContainsString('plate__band', $garage);
        self::assertStringNotContainsString('plate--gb', self::body($theirs->get('/vehicles/' . $mustang->id)));
    }

    public function testASharedVehicleShowsItsOwnersStyleToEveryone(): void
    {
        $partner = $this->createMember($this->app, 'partner', self::us());
        $theirs = $this->browserFor($this->app, 'partner');
        $golf = $this->car($this->owner($this->app), 'AB12 CDE');
        $mustang = $this->car($partner, 'MUST4NG');
        $this->share($golf, $partner);
        $this->share($mustang, $this->owner($this->app));

        $partnersGarage = self::body($theirs->get('/garage'));
        self::assertStringContainsString(self::GB_PLATE, $partnersGarage, 'the UK owner’s car is a UK plate to a US viewer');
        self::assertStringContainsString('plate--neutral"><span class="plate__text">MUST4NG', $partnersGarage);

        $ownersGarage = self::body($this->browser->get('/garage'));
        self::assertStringContainsString('plate--neutral"><span class="plate__text">MUST4NG', $ownersGarage, 'and the reverse');
        self::assertStringContainsString(self::GB_PLATE, $ownersGarage);
    }

    public function testNoRegistrationDrawsNoPlateAndNoDash(): void
    {
        $this->car($this->owner($this->app), null);

        $garage = self::body($this->browser->get('/garage'));
        self::assertStringNotContainsString('class="plate', $garage);
        self::assertMatchesRegularExpression(
            '#<div class="vehicle-card__tags">\s*<span class="pill">Petrol</span>#',
            $garage,
            'the fuel pill holds the place',
        );
        self::assertStringNotContainsString('vehicle-tile__plate', self::body($this->browser->get('/')));
    }

    public function testARegistrationIsEscapedAndALongOneCarriesATitle(): void
    {
        $this->car($this->owner($this->app), '<script>x</script>');
        $garage = self::body($this->browser->get('/garage'));
        self::assertStringNotContainsString('<SCRIPT>', $garage);
        self::assertStringContainsString('&lt;SCRIPT&gt;X&lt;/SCRIPT&gt;', $garage);
        self::assertStringContainsString('title="&lt;SCRIPT&gt;X&lt;/SCRIPT&gt;"', $garage, 'over ten characters');

        $this->resetDatabase($this->app);
        $this->browser = $this->signedIn($this->app);
        $this->car($this->owner($this->app), 'AB12 CDE');
        self::assertStringNotContainsString('title="AB12 CDE"', self::body($this->browser->get('/garage')));
    }

    public function testThePrintedHistoryKeepsTheRegistrationAsText(): void
    {
        $golf = $this->car($this->owner($this->app), 'AB12 CDE');

        $print = self::body($this->browser->get('/vehicles/' . $golf->id . '/history/print'));
        self::assertStringContainsString('AB12 CDE', $print);
        self::assertStringNotContainsString('class="plate', $print);
    }

    private function car(User $owner, ?string $registration): Vehicle
    {
        return $this->service($this->app, VehicleService::class)->create($owner, new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            registration: $registration,
        ));
    }

    private function share(Vehicle $vehicle, User $with): void
    {
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($vehicle->id, $with->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
    }

    private static function us(): DisplayPreferences
    {
        return new DisplayPreferences(
            'en_US',
            'America/New_York',
            DistanceUnit::Mile,
            VolumeUnit::UsGallon,
            ConsumptionUnit::MpgUs,
            'USD',
        );
    }
}
