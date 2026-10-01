<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Trip\TripData;
use Logbook\Repository\BackupRepository;
use Logbook\Service\Trip\TripService;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * No tool B writes anything, whatever it is asked (spec.md §7.26 *Tests*).
 */
final class ToolsBReadOnlyTest extends ToolsBTestCase
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
        $before = $this->counts($app);

        $calls = [
            ['coming_up', []],
            ['coming_up', ['vehicles' => [$golf->id], 'horizon_months' => 3]],
            ['documents', ['vehicle' => $golf->id]],
            ['documents', []],
            ['tyres', ['vehicle' => $golf->id]],
            ['trips_summary', ['period' => 'all_time']],
            ['trips_summary', []],
            ['needs_attention', []],
        ];
        foreach ($calls as [$tool, $arguments]) {
            $run = $this->call($app, $owner, $tool, $arguments);
            self::assertNull($run->error, $tool . ': ' . $run->error);
        }

        self::assertSame($before, $this->counts($app));
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
