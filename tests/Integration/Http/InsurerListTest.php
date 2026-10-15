<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\Part\PartData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ModificationRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Part\CameOff;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\RecordParts;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *For your insurer* (spec.md §7.39, #438, #444, #445): the current
 * modifications, ECU tunes first, never a cost, readable by anyone who can
 * view the vehicle; *Mark declared* and *Clear* need `Manage`.
 */
final class InsurerListTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    private const string NOW = '2026-10-14T10:00:00Z';

    /** @var App<ContainerInterface> */
    private App $app;
    private Vehicle $golf;
    private TestBrowser $me;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->pinClock($this->app, self::NOW);
        $this->me = $this->signedIn($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->base = '/vehicles/' . $this->golf->id . '/parts/insurer';
    }

    public function testListsCurrentModificationsTunesFirstWithTheirPartsAndNeverACost(): void
    {
        $browser = $this->me;
        $this->modify('Cat-back exhaust', ModificationCategory::Exhaust, '2026-03-01', '899.000', new RecordParts(
            fitNew: [new PartData('Cat-back system', brand: 'Milltek', partNumber: 'SSXVW123', price: '780.000')],
            cameOff: [new CameOff(new PartData('Standard exhaust'))],
        ));
        $this->modify('Stage 1 remap', ModificationCategory::Tune, '2026-05-10', '450.000');
        $this->modify('Coilovers', ModificationCategory::Suspension, '2026-02-01', '1240.000');
        $old = $this->modify('Old spoiler', ModificationCategory::Body, '2025-01-01', '120.000');
        $this->revert($old);

        $page = self::body($browser->get($this->base));

        $tune = strpos($page, 'Stage 1 remap');
        $exhaust = strpos($page, 'Cat-back exhaust');
        $suspension = strpos($page, 'Coilovers');
        self::assertNotFalse($tune);
        self::assertTrue($tune < $exhaust && $exhaust < $suspension, 'ECU tunes first, then the category order');
        self::assertStringContainsString('Milltek Cat-back system', $page);
        self::assertStringContainsString('SSXVW123', $page);
        self::assertStringContainsString('Replaced', $page);
        self::assertStringContainsString('Standard exhaust', $page);
        self::assertStringContainsString('Not declared yet', $page);
        self::assertStringNotContainsString('Old spoiler', $page, 'a reverted modification is no longer on the car');
        self::assertStringContainsString('1 reverted modification is not listed', $page);
        foreach (['899', '780', '450', '1,240', '£'] as $money) {
            self::assertStringNotContainsString($money, $page, 'never a cost or a price');
        }
        self::assertStringContainsString('3 current modifications', $page);
    }

    public function testMarkAllDeclaredAndClearOne(): void
    {
        $browser = $this->me;
        $a = $this->modify('Cat-back exhaust', ModificationCategory::Exhaust, '2026-03-01', '0');
        $b = $this->modify('Stage 1 remap', ModificationCategory::Tune, '2026-05-10', '0');
        $c = $this->modify('Coilovers', ModificationCategory::Suspension, '2026-02-01', '0', declared: '2026-02-20');

        $browser->post($this->base, ['declared_on' => '2026-10-01', 'only' => 'all']);

        self::assertSame('2026-10-01', $this->declared($a), 'every undeclared one, on the date given');
        self::assertSame('2026-10-01', $this->declared($b));
        self::assertSame('2026-02-20', $this->declared($c), 'one already declared is left alone');
        $page = self::body($browser->get($this->base));
        self::assertStringContainsString('Declared on 1 Oct 2026', $page);
        self::assertStringNotContainsString('Not declared yet', $page);

        $browser->post($this->base, ['declared_on' => '2026-10-01', 'clear' => (string) $a->id]);
        self::assertNull($this->declared($a), 'Clear puts it back to not declared');
        self::assertSame('2026-10-01', $this->declared($b));

        $browser->post($this->base, ['declared_on' => '2026-10-12', 'only' => (string) $a->id]);
        self::assertSame('2026-10-12', $this->declared($a), 'one row on its own');
    }

    public function testTheDateIsNotInTheFutureAndNotBeforeTheModification(): void
    {
        $browser = $this->me;
        $a = $this->modify('Cat-back exhaust', ModificationCategory::Exhaust, '2026-03-01', '0');

        $browser->post($this->base, ['declared_on' => '2026-11-01', 'only' => 'all']);
        self::assertNull($this->declared($a), 'not in the future');
        $browser->post($this->base, ['declared_on' => 'not a date', 'only' => 'all']);
        self::assertNull($this->declared($a));

        $browser->post($this->base, ['declared_on' => '2026-02-01', 'only' => 'all']);
        self::assertNull($this->declared($a), 'not before it was done');
        $page = self::body($browser->follow($browser->post($this->base, ['declared_on' => '2026-02-01', 'only' => 'all'])));
        self::assertStringContainsString('was done on 1 Mar 2026', $page);

        $browser->post($this->base, ['declared_on' => '2026-03-01', 'only' => 'all']);
        self::assertSame('2026-03-01', $this->declared($a), 'the day it was done is fine');
    }

    public function testAnyoneWhoCanViewReadsItAndOnlyManageDeclares(): void
    {
        $a = $this->modify('Stage 1 remap', ModificationCategory::Tune, '2026-05-10', '450.000');
        foreach ([ShareLevel::View, ShareLevel::Log, ShareLevel::Manage] as $level) {
            $member = $this->createMember($this->app, $level->value);
            $this->service($this->app, VehicleShareRepository::class)
                ->insert($this->golf->id, $member->id, $level, false, false, new DateTimeImmutable(self::NOW));
        }

        foreach (['view', 'log'] as $name) {
            $theirs = $this->browserFor($this->app, $name);
            $page = self::body($theirs->get($this->base));
            self::assertStringContainsString('Stage 1 remap', $page, $name . ' can read it');
            self::assertStringNotContainsString('Mark declared', $page, $name . ' has no buttons');
            self::assertStringNotContainsString('450', $page, 'no costs, whoever reads');
            $declare = $theirs->post($this->base, ['declared_on' => '2026-10-01', 'only' => 'all']);
            self::assertSame(403, $declare->getStatusCode());
        }
        self::assertNull($this->declared($a));

        $manager = $this->browserFor($this->app, 'manage');
        self::assertStringContainsString('Mark declared', self::body($manager->get($this->base)));
        $manager->post($this->base, ['declared_on' => '2026-10-01', 'only' => 'all']);
        self::assertSame('2026-10-01', $this->declared($a));
    }

    public function testTheBadgeOnTheTabAndThePageAndTheLink(): void
    {
        $browser = $this->me;
        $a = $this->modify('Stage 1 remap', ModificationCategory::Tune, '2026-05-10', '0');
        $tab = '/vehicles/' . $this->golf->id . '/parts';

        $html = self::body($browser->get($tab));
        self::assertStringContainsString('Not declared yet', $html);
        self::assertStringContainsString('href="' . $this->base . '"', $html, 'linked from the Modifications heading');
        self::assertStringContainsString('Not declared yet', self::body($browser->get('/vehicles/' . $this->golf->id . '/modifications/' . $a->id)));

        $browser->post($this->base, ['declared_on' => '2026-10-01', 'only' => 'all']);
        self::assertStringNotContainsString('Not declared yet', self::body($browser->get($tab)));
    }

    public function testNothingToListNoNeedlessLinkAndArchivedAndModuleOff(): void
    {
        $browser = $this->me;
        self::assertStringContainsString('No current modifications', self::body($browser->get($this->base)));
        self::assertStringNotContainsString($this->base . '"', self::body($browser->get('/vehicles/' . $this->golf->id . '/parts')));

        $a = $this->modify('Stage 1 remap', ModificationCategory::Tune, '2026-05-10', '0');
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $this->golf);
        $browser->post($this->base, ['declared_on' => '2026-10-01', 'only' => 'all']);
        self::assertNull($this->declared($a), 'an archived vehicle is read-only');
        self::assertStringNotContainsString('Mark declared', self::body($browser->get($this->base)));

        $this->service($this->app, FeatureToggles::class)->save([Feature::Fuel, Feature::Maintenance, Feature::Reports]);
        self::assertSame(404, $browser->get($this->base)->getStatusCode());
        $declare = $browser->post($this->base, ['declared_on' => '2026-10-01', 'only' => 'all']);
        self::assertSame(404, $declare->getStatusCode());
    }

    private function modify(
        string $title,
        ModificationCategory $category,
        string $on,
        string $cost,
        ?RecordParts $parts = null,
        ?string $declared = null,
    ): Modification {
        $data = new ModificationData(
            $title,
            $category,
            self::day($on),
            cost: $cost === '0' ? '0' : $cost,
            declaredOn: $declared === null ? null : self::day($declared),
        );

        return $this->service($this->app, ModificationService::class)
            ->create($this->golf, $data, new DateTimeZone('UTC'), parts: $parts);
    }

    private function revert(Modification $modification): void
    {
        $this->service($this->app, ModificationService::class)->revert(
            $this->golf,
            $modification,
            self::day('2026-06-01'),
            null,
            null,
            [],
            [],
            new DateTimeZone('UTC'),
        );
    }

    private function declared(Modification $modification): ?string
    {
        $stored = $this->service($this->app, ModificationRepository::class)->find($this->golf->id, $modification->id);

        return $stored?->data->declaredOn?->format('Y-m-d');
    }
}
