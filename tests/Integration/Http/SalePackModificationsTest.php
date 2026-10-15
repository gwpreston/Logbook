<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Part\CameOff;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartRemoval;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\RecordParts;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *Include modifications* in the sale pack (spec.md §7.19, §7.39, #439): off
 * unless ticked; current modifications with their parts, those put back to
 * standard, and the parts kept with the car; never a cost or a price, and
 * not at all with the module off.
 */
final class SalePackModificationsTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $me;
    private Vehicle $golf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->pinClock($this->app, self::NOW);
        $this->me = $this->signedIn($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->build();
    }

    public function testOffUnlessTickedAndThenListsTheThreeGroupsWithoutAnyAmount(): void
    {
        $off = $this->page([]);
        self::assertStringNotContainsString('Milltek', $off);
        self::assertStringContainsString('name="modifications" value="1"', $off, 'the option is offered');
        self::assertStringNotContainsString('name="modifications" value="1" checked', $off);

        $on = $this->page(['options' => '1', 'modifications' => '1', 'costs' => '1']);
        $sheet = substr($on, (int) strpos($on, 'sale-pack__sheet'));
        self::assertStringContainsString('Modifications can affect insurance', $on);
        self::assertStringContainsString('Cat-back exhaust', $sheet);
        self::assertStringContainsString('Milltek Cat-back system (SSXVW123)', $sheet, 'the parts it fitted, with numbers');
        self::assertStringContainsString('Fitted by Self', $sheet);
        self::assertStringContainsString('Returned to standard', $sheet);
        self::assertStringContainsString('Stage 1 remap', $sheet);
        self::assertStringContainsString('Put back to standard on 1 Jun 2026', $sheet);
        self::assertStringContainsString('Standard dampers', $sheet, 'the original parts that went back on');
        self::assertStringContainsString('Kept with the car', $sheet);
        self::assertStringContainsString('Standard exhaust', $sheet, 'a stored original part');
        self::assertStringContainsString('Original part', $sheet);
        self::assertStringContainsString('Spare', $sheet, 'a spare bought ahead');
        foreach (['899', '780', '450', '1,240', '120', '55', '77', '33.00', '£'] as $money) {
            self::assertStringNotContainsString($money, $sheet, 'never a cost, a price or a sale: ' . $money);
        }
    }

    public function testTheLinksKeepTheChoiceAndTheModuleOffRemovesIt(): void
    {
        $on = $this->page(['options' => '1', 'modifications' => '1']);
        $decoded = html_entity_decode($on);
        self::assertStringContainsString('modifications=1', $decoded, 'the pack and ZIP links reproduce the choice');

        $this->service($this->app, FeatureToggles::class)->save([Feature::Fuel, Feature::Maintenance, Feature::Reports]);
        $off = $this->page(['options' => '1', 'modifications' => '1']);
        self::assertStringNotContainsString('Cat-back exhaust', $off);
        self::assertStringNotContainsString('name="modifications"', $off, 'no option with the module off');
    }

    private function build(): void
    {
        $zone = new DateTimeZone('UTC');
        $mods = $this->service($this->app, ModificationService::class);
        $parts = $this->service($this->app, PartService::class);
        $mods->create($this->golf, new ModificationData(
            'Cat-back exhaust',
            ModificationCategory::Exhaust,
            self::day('2026-03-01'),
            cost: '899.000',
            fittedBy: 'Self',
        ), $zone, parts: new RecordParts(
            fitNew: [new PartData('Cat-back system', brand: 'Milltek', partNumber: 'SSXVW123', price: '780.000')],
            cameOff: [new CameOff(new PartData('Standard exhaust'), new PartRemoval(RemovalOutcome::Kept))],
        ));
        $coilovers = $mods->create($this->golf, new ModificationData(
            'Coilovers',
            ModificationCategory::Suspension,
            self::day('2026-02-01'),
            cost: '1240.000',
        ), $zone, parts: new RecordParts(
            fitNew: [new PartData('B14 coilover kit', brand: 'Bilstein', price: '899.000')],
            cameOff: [new CameOff(new PartData('Standard dampers'), new PartRemoval(RemovalOutcome::Kept))],
        ));
        $dampers = null;
        foreach ($parts->list($this->golf) as $part) {
            if ($part->data->name === 'Standard dampers') {
                $dampers = $part;
            }
        }
        self::assertNotNull($dampers);
        $mods->revert($this->golf, $coilovers, self::day('2026-06-01'), null, null, [], [$dampers->id], $zone);
        $mods->create($this->golf, new ModificationData(
            'Stage 1 remap',
            ModificationCategory::Tune,
            self::day('2026-05-10'),
            cost: '450.000',
        ), $zone);
        // A spare bought ahead, and one sold (its price must never show).
        $parts->create($this->golf, new PartData('Spare wheel', price: '55.000'), $zone);
        $sold = $parts->create(
            $this->golf,
            new PartData('Sold mirror', price: '77.000'),
            $zone,
            fitOn: self::day('2026-01-10'),
        );
        $parts->remove($this->golf, $sold, self::day('2026-04-01'), null, new PartRemoval(RemovalOutcome::Sold, '33.000'), $zone);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function page(array $query): string
    {
        $path = '/vehicles/' . $this->golf->id . '/sale-pack';
        $response = $this->me->get($path . ($query === [] ? '' : '?' . http_build_query($query)));
        self::assertSame(200, $response->getStatusCode());

        return self::body($response);
    }

    private static function day(string $date): DateTimeImmutable
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);

        return $day;
    }
}
