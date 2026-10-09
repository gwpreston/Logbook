<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Attention\AttentionSettingsStore;
use Logbook\Service\Attention\AttentionThresholds;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *Economy up* (spec.md §7.8, Phase 42) on the dashboard and the Insights
 * page: the drift check judged for an improvement, by the vehicle owner's
 * threshold whoever looks; never beside a drift item for the same series;
 * gone with the Fuel module. Eight 600 km baseline tanks at 5.75 L/100 km,
 * then five at 5.0: 15% better. "Now" is 1 Oct 2026.
 */
final class EconomyUpTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-10-01T10:00:00Z';

    public function testAnImprovementIsAnInsightAndNeverADriftItem(): void
    {
        [$app, $golf] = $this->scene('5.75', '5.0');
        $browser = $this->browserFor($app, 'owner');

        $page = self::text(self::body($browser->get('/insights')));
        self::assertStringContainsString('Economy is up about 15%', $page);
        self::assertStringContainsString(
            'Volkswagen Golf: 56.5 mpg over the last 5 tanks, against your 12-month average of 49.1 mpg. '
            . 'This may include the time of year: there’s no data for these months last year.',
            $page,
        );
        $dashboard = self::body($browser->get('/'));
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/fuel" data-insight="economy_up"', $dashboard);
        self::assertStringNotContainsString('worse over the last', $dashboard, 'no drift item for an improvement');

        $this->service($app, FeatureToggles::class)->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => $f !== Feature::Fuel,
        )));
        self::assertStringNotContainsString('Economy is up', self::body($browser->get('/insights')));
    }

    public function testAFallIsADriftItemAndNeverAnInsight(): void
    {
        [$app] = $this->scene('5.0', '5.75');
        $browser = $this->browserFor($app, 'owner');

        self::assertStringNotContainsString('Economy is up', self::body($browser->get('/insights')));
        // In mpg, 49.1 against 56.5.
        self::assertStringContainsString('Economy is about 13% worse', self::text(self::body($browser->get('/'))));
    }

    public function testTheOwnersThresholdDecidesForEveryViewer(): void
    {
        [$app, $golf] = $this->scene('5.75', '5.0');
        $partner = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $partner->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        $settings = $this->service($app, AttentionSettingsStore::class);
        // The partner would flag 5%; the owner asks for 20%.
        $settings->saveThresholds($partner->id, new AttentionThresholds(driftPercent: 5));
        $settings->saveThresholds($this->owner($app)->id, new AttentionThresholds(driftPercent: 20));

        self::assertStringNotContainsString('Economy is up', self::body($this->browserFor($app, 'partner')->get('/insights')));
        self::assertStringNotContainsString('Economy is up', self::body($this->browserFor($app, 'owner')->get('/insights')));

        $settings->saveThresholds($this->owner($app)->id, new AttentionThresholds(driftPercent: 10));
        self::assertStringContainsString(
            'Economy is up about 15%',
            self::body($this->browserFor($app, 'partner')->get('/insights')),
            'the owner\'s 10% decides, not costs: economy is no amount',
        );
    }

    /**
     * @return array{App<ContainerInterface>, Vehicle}
     */
    private function scene(string $before, string $after): array
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->pinClock($app, self::NOW);
        $this->createOwner($app);
        $this->service($app, FeatureToggles::class)->save(Feature::cases());
        $golf = $this->vehicle($app);
        $at = new DateTimeImmutable('2025-11-01T09:00:00Z');
        $km = 10000;
        $this->fillUp($app, $golf, $at->modify('-28 days')->format('Y-m-d\TH:i:s\Z'), (string) $km, '40', '56.00');
        for ($i = 0; $i < 8; $i++) {
            $km += 600;
            $this->fill($app, $golf, $at->modify(sprintf('+%d days', 28 * $i)), $km, $before);
        }
        $recent = new DateTimeImmutable('2026-07-01T09:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $km += 600;
            $this->fill($app, $golf, $recent->modify(sprintf('+%d days', 18 * $i)), $km, $after);
        }

        return [$app, $golf];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function fill(App $app, Vehicle $vehicle, DateTimeImmutable $at, int $km, string $per100): void
    {
        $litres = number_format((float) $per100 * 6, 3, '.', '');
        $total = number_format((float) $litres * 1.4, 2, '.', '');
        $this->fillUp($app, $vehicle, $at->format('Y-m-d\TH:i:s\Z'), (string) $km, $litres, $total);
    }

    /** The page's text with tags and repeated spaces gone, entities decoded. */
    private static function text(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
