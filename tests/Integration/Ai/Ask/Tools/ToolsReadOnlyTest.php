<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Trip\TripData;
use Logbook\Repository\BackupRepository;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Trip\TripService;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * No tool writes anything, whatever it is asked (spec.md §7.26 *Tests*).
 */
final class ToolsReadOnlyTest extends ToolsBTestCase
{
    public function testEveryToolLeavesEveryTableAsItWas(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_TRIPS' => 'true']);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-08-01T09:00:00Z', '20000', '40', '60.00');
        $this->document($app, $golf, ComplianceType::Insurance, '2025-10-01', '2026-09-30', '290.00');
        $this->service($app, TripService::class)->create($golf, new TripData(
            new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            false,
            '40',
            purpose: 'Client visit',
        ));
        $this->maintenance($app, $golf, '2026-07-01', 'Oil and filter', '120.00', '19500');
        $this->reading($app, $golf, '20500', '2026-09-20T09:00:00Z');
        $before = $this->counts($app);

        $calls = [
            ['find_vehicles', ['query' => 'Golf']],
            ['vehicle_summary', ['vehicle' => $golf->id]],
            ['costs', ['period' => 'all_time', 'group_by' => 'month']],
            ['cost_per_distance', ['period' => 'this_year']],
            ['fuel_stats', ['vehicle' => $golf->id, 'period' => 'all_time']],
            ['fuel_stats', []],
            ['maintenance', ['vehicle' => $golf->id, 'text' => 'oil']],
            ['last_done', ['vehicle' => $golf->id, 'category' => 'service']],
            ['mileage', ['vehicle' => $golf->id, 'period' => 'this_year']],
            ['ownership', ['vehicle' => $golf->id]],
            ['coming_up', []],
            ['coming_up', ['vehicles' => [$golf->id], 'horizon_months' => 3]],
            ['documents', ['vehicle' => $golf->id]],
            ['documents', []],
            ['tyres', ['vehicle' => $golf->id]],
            ['trips_summary', ['period' => 'all_time']],
            ['trips_summary', []],
            ['needs_attention', []],
            ['incidents', []],
            ['incidents', ['years' => 10, 'claims_only' => true]],
            // Phase 26.3: drafts are validated by a write that is rolled back; only ai_drafts keeps the card.
            ['draft_fill_up', ['vehicle' => $golf->id, 'odometer' => '21000', 'volume' => '40', 'total_cost' => '60']],
            ['draft_reading', ['vehicle' => $golf->id, 'odometer' => '21100']],
            ['draft_service_record', ['vehicle' => $golf->id, 'category' => 'service', 'title' => 'Service', 'cost' => '150']],
            ['draft_document', ['vehicle' => $golf->id, 'type' => 'MOT', 'start' => 'today', 'term' => 'a year']],
            ['draft_expense', ['vehicle' => $golf->id, 'category' => 'parking', 'amount' => '4.50']],
            ['draft_tyre_check', ['vehicle' => $golf->id, 'depths' => ['fl' => '5']]],
            ['draft_reminder', ['vehicle' => $golf->id, 'title' => 'Wash it', 'due' => '2026-12-01']],
            ['draft_incident', ['vehicle' => $golf->id, 'type' => 'pothole', 'damage_areas' => ['wheels']]],
        ];
        foreach ($calls as [$tool, $arguments]) {
            $run = $this->call($app, $owner, $tool, $arguments);
            self::assertNull($run->error, $tool . ': ' . $run->error);
        }

        $after = $this->counts($app);
        $drafts = $after['ai_drafts'];
        unset($before['ai_drafts'], $after['ai_drafts']);
        self::assertSame($before, $after);
        self::assertSame(
            7,
            $drafts,
            'a card for each draft but the tread check (no tyres fitted)',
        );
        self::assertCount(23, $this->service($app, ToolRegistry::class)->names(), 'every tool was tried');
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, int>
     */
    private function counts(App $app): array
    {
        $connection = $this->connection($app);
        $counts = [];
        foreach ($this->service($app, BackupRepository::class)->tableNames() as $table) {
            $count = $connection->createQueryBuilder()->select('COUNT(*)')->from($table)->fetchOne();
            $counts[$table] = is_numeric($count) ? (int) $count : -1;
        }

        return $counts;
    }
}
