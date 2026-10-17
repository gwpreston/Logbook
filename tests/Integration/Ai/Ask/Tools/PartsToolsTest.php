<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\Part\WarrantyStart;
use Logbook\Repository\ModificationRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Part\CameOff;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartRemoval;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\RecordParts;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\JsonDoc;
use Logbook\Tests\Support\QueryCounter;

/**
 * `modifications`, `parts` and `draft_modification` (Phase 50.2, spec.md §7.26, §7.39, #441): the
 * build work and its parts as the pages word them, costs and prices only with `ViewCosts`, build
 * spend as its own figure, and a draft card whose Add writes the modification.
 */
final class PartsToolsTest extends ToolsBTestCase
{
    public function testModificationsWithTheirPartsAndTheCostOnlyForThoseWhoSeeCosts(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_PARTS' => 'true']);
        $golf = $this->vehicle($app);
        $zone = new DateTimeZone('Europe/London');
        $day = static fn (string $date): DateTimeImmutable => LocalTime::parseDate($date) ?? throw new \LogicException($date);
        $mods = $this->service($app, ModificationService::class);
        $mods->create($golf, new ModificationData(
            'Cat-back exhaust',
            ModificationCategory::Exhaust,
            $day('2026-03-01'),
            '37000.000',
            '899.000',
            'Self',
            declaredOn: $day('2026-03-20'),
        ), $zone, parts: new RecordParts(
            fitNew: [new PartData('Cat-back system', brand: 'Milltek', partNumber: 'SSXVW123', price: '780.000')],
            cameOff: [new CameOff(new PartData('Standard exhaust'), new PartRemoval(RemovalOutcome::Kept))],
        ));
        $spoiler = new ModificationData('Old spoiler', ModificationCategory::Body, $day('2025-01-01'), cost: '120.000');
        $old = $mods->create($golf, $spoiler, $zone);
        $mods->revert($golf, $old, $day('2025-06-01'), null, null, [], [], $zone);
        $this->assertSchemaAccepts($app, $owner, 'modifications', ['vehicles' => [$golf->id], 'state' => 'reverted']);

        $current = $this->toolResult($app, $owner, 'modifications', ['vehicles' => [$golf->id]]);
        $data = new JsonDoc($current->data);
        self::assertSame(1, $data->int('modifications'));
        self::assertSame('Cat-back exhaust', $data->get('rows', 0, 'title'));
        self::assertSame('Exhaust', $data->get('rows', 0, 'category'));
        self::assertTrue($data->get('rows', 0, 'declared_to_insurer'));
        self::assertSame(['Milltek Cat-back system (SSXVW123)'], $data->get('rows', 0, 'parts_fitted'));
        self::assertSame(['Standard exhaust · Kept'], $data->get('rows', 0, 'parts_removed'));
        self::assertSame('899.00', $data->get('rows', 0, 'cost', 'amount'));
        self::assertSame('899.00', $data->get('build_total', 0, 'amount'), 'build spend, as its own figure');
        self::assertSame('/vehicles/' . $golf->id . '/parts', $current->link);

        $all = new JsonDoc($this->toolResult($app, $owner, 'modifications', ['vehicles' => [$golf->id], 'state' => 'all'])->data);
        self::assertSame(2, $all->int('modifications'));
        self::assertSame('1019.00', $all->get('build_total', 0, 'amount'), 'a reverted one was still spent');
        $reverted = new JsonDoc($this->toolResult($app, $owner, 'modifications', ['state' => 'reverted'])->data);
        self::assertSame('Old spoiler', $reverted->get('rows', 0, 'title'));
        self::assertTrue($reverted->get('rows', 0, 'reverted'));

        $member = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-10-01'));
        $theirs = new JsonDoc($this->toolResult($app, $member, 'modifications', ['vehicles' => [$golf->id]])->data);
        self::assertSame('Cat-back exhaust', $theirs->get('rows', 0, 'title'));
        self::assertNull($theirs->get('rows', 0, 'cost'), 'no cost without Can see costs');
        self::assertNull($theirs->get('build_total'));

        // Both vehicles in one answer: the total leaves the hidden one out and says so.
        $fiesta = $this->vehicle($app, 'Ford', 'Fiesta');
        $wrap = new ModificationData('Wrap', ModificationCategory::Body, $day('2026-02-01'), cost: '500.000');
        $mods->create($fiesta, $wrap, $zone);
        $this->service($app, VehicleShareRepository::class)
            ->insert($fiesta->id, $member->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-10-01'));
        $both = new JsonDoc($this->toolResult($app, $member, 'modifications')->data);
        self::assertSame(2, $both->int('modifications'));
        self::assertNull($both->get('build_total'), 'a member who sees no costs gets no total');
        $mine = new JsonDoc($this->toolResult($app, $owner, 'modifications')->data);
        self::assertStringNotContainsString('leaves out', $mine->string('note'), 'nothing hidden from the owner');
    }

    public function testPartsWithTheirWarrantyDistanceAndFittings(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_PARTS' => 'true']);
        $golf = $this->vehicle($app);
        $zone = new DateTimeZone('Europe/London');
        $day = static fn (string $date): DateTimeImmutable => LocalTime::parseDate($date) ?? throw new \LogicException($date);
        $parts = $this->service($app, PartService::class);
        $parts->create($golf, new PartData(
            'Clutch kit',
            brand: 'Sachs',
            partNumber: 'CK1',
            boughtOn: $day('2026-03-01'),
            price: '320.000',
            warrantyMonths: 24,
            warrantyFrom: WarrantyStart::Purchase,
        ), $zone, fitOn: $day('2026-03-05'), fitKm: '40000.000');
        $parts->create($golf, new PartData('Spare bulb'), $zone);
        $this->assertSchemaAccepts($app, $owner, 'parts', ['vehicles' => [$golf->id], 'status' => 'all']);

        $fitted = new JsonDoc($this->toolResult($app, $owner, 'parts', ['vehicles' => [$golf->id]])->data);
        self::assertSame(1, $fitted->int('parts'), 'fitted by default');
        self::assertSame('Sachs Clutch kit', $fitted->get('rows', 0, 'name'));
        self::assertSame('Fitted', $fitted->get('rows', 0, 'status'));
        self::assertTrue($fitted->get('rows', 0, 'warranty', 'active'));
        self::assertSame('2028-03-01', $fitted->get('rows', 0, 'warranty', 'until'));
        self::assertStringStartsWith('In warranty until', $fitted->string('rows', 0, 'warranty', 'display'));
        self::assertSame('320.00', $fitted->get('rows', 0, 'price', 'amount'));
        self::assertSame('2026-03-05', $fitted->get('rows', 0, 'fittings', 0, 'fitted_on'));

        $all = new JsonDoc($this->toolResult($app, $owner, 'parts', ['status' => 'all'])->data);
        self::assertSame(2, $all->int('parts'));
        $stored = new JsonDoc($this->toolResult($app, $owner, 'parts', ['status' => 'stored'])->data);
        self::assertSame('Spare bulb', $stored->get('rows', 0, 'name'));
        self::assertNull($stored->get('rows', 0, 'warranty'), 'a part without a warranty says nothing');

        $member = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-10-01'));
        $theirs = new JsonDoc($this->toolResult($app, $member, 'parts', ['vehicles' => [$golf->id]])->data);
        self::assertNull($theirs->get('rows', 0, 'price'), 'no price without Can see costs');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function warrantyWords(): iterable
    {
        yield 'English' => ['en_GB', 'Warranty ended 1 Jan 2025', 'In warranty until 1 Mar 2028 or 100,000 km'];
        yield 'German' => ['de_DE', 'Garantie endete am 01.01.2025', 'Garantie bis 01.03.2028 oder 100.000 km'];
    }

    /**
     * K17: the tool words a warranty with the part page's strings, so German has no English "or" and
     * an ended warranty keeps its end date.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('warrantyWords')]
    public function testAWarrantyIsWordedAsThePartPageWordsIt(string $locale, string $ended, string $both): void
    {
        $metric = UnitPreset::Metric;
        [$app, $owner] = $this->askApp(['FEATURES_PARTS' => 'true'], new DisplayPreferences(
            $locale,
            'Europe/London',
            $metric->distance(),
            $metric->volume(),
            $metric->consumption(),
            'GBP',
        ));
        $golf = $this->vehicle($app);
        $zone = new DateTimeZone('Europe/London');
        $day = static fn (string $date): DateTimeImmutable => LocalTime::parseDate($date) ?? throw new \LogicException($date);
        $parts = $this->service($app, PartService::class);
        $parts->create($golf, new PartData(
            'Battery',
            boughtOn: $day('2024-01-01'),
            warrantyMonths: 12,
            warrantyFrom: WarrantyStart::Purchase,
        ), $zone, fitOn: $day('2024-01-02'), fitKm: '30000.000');
        $parts->create($golf, new PartData(
            'Clutch kit',
            boughtOn: $day('2026-03-01'),
            warrantyMonths: 24,
            warrantyKm: '60000.000',
            warrantyFrom: WarrantyStart::Purchase,
        ), $zone, fitOn: $day('2026-03-05'), fitKm: '40000.000');

        $rows = new JsonDoc($this->toolResult($app, $owner, 'parts', ['vehicles' => [$golf->id]])->data);
        $display = [];
        foreach ([0, 1] as $i) {
            $display[$rows->string('rows', $i, 'name')] = $rows->string('rows', $i, 'warranty', 'display');
        }
        self::assertSame($ended, $display['Battery'], 'an ended warranty keeps its date');
        self::assertSame($both, $display['Clutch kit'], 'both limits, joined by the translated "or"');
    }

    /**
     * K31: the vehicle's latest reading is read once for its parts, not twice per part.
     */
    public function testThePartsToolReadsTheLatestOdometerOncePerVehicle(): void
    {
        $app = $this->aiApp(['FEATURES_PARTS' => 'true']);
        $counter = QueryCounter::install($app);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app, 'owner');
        $connection = $this->network($app, 'Ollama on the desktop', 'http://192.168.1.20:11434/v1');
        $this->assign($app, $connection, 'llama3.2:3b', AiTaskName::Ask, [Capability::Tools]);
        $golf = $this->vehicle($app);
        $zone = new DateTimeZone('Europe/London');
        $on = LocalTime::parseDate('2026-03-05') ?? throw new \LogicException('date');
        $parts = $this->service($app, PartService::class);
        foreach (['Clutch kit', 'Battery', 'Wiper blades'] as $name) {
            $parts->create($golf, new PartData($name, warrantyMonths: 24), $zone, fitOn: $on, fitKm: '40000.000');
        }

        $sent = $counter->histogram(fn () => $this->toolResult($app, $owner, 'parts', ['vehicles' => [$golf->id]]));
        $latest = array_sum(array_filter(
            $sent,
            static fn (string $sql): bool => str_contains($sql, 'reading_km') && str_contains($sql, 'odometer_readings'),
            ARRAY_FILTER_USE_KEY,
        ));
        self::assertSame(1, $latest, 'one latest-reading query for three parts');
    }

    public function testADraftModificationIsACardUntilAddAndHasNoParts(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_PARTS' => 'true']);
        $golf = $this->vehicle($app);

        $result = $this->toolResult($app, $owner, 'draft_modification', [
            'vehicle' => $golf->id,
            'title' => 'Milltek cat-back exhaust',
            'category' => 'cat back',
            'fitted_by' => 'Self',
            'cost' => '899',
            'notes' => 'Fitted at the weekend',
        ]);
        $card = new JsonDoc($result->data);
        self::assertCount(0, $this->saved($app, $golf->id), 'nothing saved yet');

        $this->service($app, DraftStore::class)->apply($owner, $card->int('draft_id'));
        $saved = $this->saved($app, $golf->id);
        self::assertCount(1, $saved);
        self::assertSame('Milltek cat-back exhaust', $saved[0]->data->title);
        self::assertSame(ModificationCategory::Exhaust, $saved[0]->data->category, 'a word Logbook resolves');
        self::assertSame('Self', $saved[0]->data->fittedBy);
        self::assertSame('899.000', $saved[0]->data->cost);
        self::assertSame([], $this->service($app, PartService::class)->list($golf), 'parts are added in the form');
    }

    /**
     * @param \Slim\App<\Psr\Container\ContainerInterface> $app
     * @return list<\Logbook\Domain\Part\Modification>
     */
    private function saved(\Slim\App $app, int $vehicleId): array
    {
        return $this->service($app, ModificationRepository::class)->listForVehicle($vehicleId);
    }

    public function testAMemberWithoutCostsDraftsNoCostAndTheModuleOffHidesAllThree(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_PARTS' => 'true']);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-10-01'));
        $result = $this->toolResult($app, $member, 'draft_modification', [
            'vehicle' => $golf->id, 'title' => 'Wrap', 'cost' => '500',
        ]);
        $this->service($app, DraftStore::class)->apply($member, (new JsonDoc($result->data))->int('draft_id'));
        $saved = $this->saved($app, $golf->id);
        self::assertSame('0.000', $saved[0]->data->cost, 'a cost needs Can see costs');

        [$off, $user] = $this->askApp(['FEATURES_PARTS' => 'false']);
        $this->vehicle($off);
        $names = array_map(
            static fn ($definition): string => $definition->name,
            $this->service($off, \Logbook\Service\Ai\Ask\ToolRegistry::class)->definitions($user),
        );
        foreach (['modifications', 'parts', 'draft_modification'] as $tool) {
            self::assertNotContains($tool, $names);
        }
    }
}
