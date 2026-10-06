<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Demo;

use DateTimeImmutable;
use Logbook\Repository\BackupRepository;
use Logbook\Service\Demo\DemoSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The sample data's dates (spec.md §7.36): every one is placed relative to
 * the day the demo is seeded, so *Last 12 months*, *Coming up*, the reminders
 * and the economy checks always have something to show.
 */
final class DemoSampleDataTest extends DemoTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function days(): array
    {
        return [
            'the day the sample was written' => ['2026-10-06'],
            'a year on' => ['2027-10-06'],
            'two and a half years on' => ['2029-04-17'],
        ];
    }

    /**
     * @param string $day an ISO date
     */
    #[DataProvider('days')]
    public function testTheSameShapeRelativeToAnyDay(string $day): void
    {
        $app = $this->demoApp();
        $today = new DateTimeImmutable($day . 'T09:00:00Z');
        $shape = $this->seedAt($app, $today);
        $reference = $this->seedAt($app, new DateTimeImmutable('2026-10-06T09:00:00Z'));

        // The same rows in every table.
        self::assertSame($reference['counts'], $shape['counts']);
        self::assertGreaterThan(200, $shape['counts']['fuel_entries']);

        // The history ends just before today (the shift is whole weeks, so a few days at most).
        self::assertGreaterThanOrEqual(5, $shape['last_fill_days_ago']);
        self::assertLessThanOrEqual(30, $shape['last_fill_days_ago']);
        self::assertLessThanOrEqual(6, abs($shape['last_fill_days_ago'] - $reference['last_fill_days_ago']));

        // Last 12 months, Coming up and the checks have something to show.
        self::assertGreaterThan(20, $shape['fills_last_12_months']);
        self::assertGreaterThan(0, $shape['documents_expiring_after_today'], 'something is coming up');
        self::assertGreaterThan(0, $shape['documents_expired_before_today'], 'and something has lapsed');
        self::assertGreaterThan(0, $shape['reminders_after_today']);
        self::assertSame($reference['economy_confirmed'], $shape['economy_confirmed'], 'the economy checks are there');
        self::assertGreaterThan(0, $shape['economy_confirmed']);
        self::assertSame($reference['trips_last_12_months'] > 0, $shape['trips_last_12_months'] > 0);
    }

    public function testTheWeekdaysKeepTheirShape(): void
    {
        // The commute trips are on weekdays, so a shift of whole weeks keeps them there.
        $app = $this->demoApp();
        $this->seedAt($app, new DateTimeImmutable('2028-03-14T09:00:00Z'));
        [$total, $weekend] = $this->businessTrips($app);
        $this->seedAt($app, new DateTimeImmutable('2026-10-06T09:00:00Z'));
        [, $original] = $this->businessTrips($app);

        self::assertGreaterThan(0, $total);
        self::assertSame($original, $weekend);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{0: int, 1: int} the business trips, and how many of them are on a weekend
     */
    private function businessTrips(App $app): array
    {
        $total = 0;
        $weekend = 0;
        foreach ($this->connection($app)->fetchFirstColumn('SELECT travelled_on FROM trips WHERE is_business = 1') as $date) {
            $total++;
            $day = (new DateTimeImmutable(is_string($date) ? $date : 'now'))->format('N');
            $weekend += in_array($day, ['6', '7'], true) ? 1 : 0;
        }

        return [$total, $weekend];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{
     *     counts: array<string, int>, last_fill_days_ago: int, fills_last_12_months: int,
     *     documents_expiring_after_today: int, documents_expired_before_today: int, reminders_after_today: int,
     *     economy_confirmed: int, trips_last_12_months: int,
     * }
     */
    private function seedAt(App $app, DateTimeImmutable $today): array
    {
        $this->resetDatabase($app);
        $connection = $this->connection($app);
        $seeder = $this->service($app, DemoSeeder::class);
        $connection->transactional(static fn () => $seeder->seed(self::DEMO_PASSWORD, $today));
        $count = static function (string $sql, array $params = []) use ($connection): int {
            $value = $connection->fetchOne($sql, array_values($params));

            return is_numeric($value) ? (int) $value : 0;
        };

        $counts = [];
        foreach (BackupRepository::TABLES as $table) {
            if (in_array($table, ['settings', 'ai_connections', 'ai_models', 'ai_tasks', 'api_keys'], true)) {
                continue;
            }
            $counts[$table] = $count('SELECT COUNT(*) FROM ' . $table);
        }
        $day = $today->format('Y-m-d');
        $yearAgo = $today->modify('-12 months')->format('Y-m-d');
        $latest = $connection->fetchOne('SELECT MAX(filled_at) FROM fuel_entries');
        self::assertIsString($latest);

        return [
            'counts' => $counts,
            'last_fill_days_ago' => intdiv(
                $today->getTimestamp() - (new DateTimeImmutable($latest . ' UTC'))->getTimestamp(),
                86400,
            ),
            'fills_last_12_months' => $count(
                'SELECT COUNT(*) FROM fuel_entries WHERE filled_at >= ? AND filled_at <= ?',
                [$yearAgo . ' 00:00:00', $day . ' 23:59:59'],
            ),
            'documents_expiring_after_today' => $count(
                'SELECT COUNT(*) FROM compliance_documents WHERE expiry_on > ?',
                [$day],
            ),
            'documents_expired_before_today' => $count(
                'SELECT COUNT(*) FROM compliance_documents WHERE expiry_on < ?',
                [$day],
            ),
            'reminders_after_today' => $count('SELECT COUNT(*) FROM reminders WHERE due_on > ?', [$day]),
            'economy_confirmed' => $count(
                'SELECT COUNT(*) FROM fuel_entries WHERE economy_confirmed IS NOT NULL',
            ),
            'trips_last_12_months' => $count(
                'SELECT COUNT(*) FROM trips WHERE travelled_on >= ? AND travelled_on <= ?',
                [$yearAgo, $day],
            ),
        ];
    }
}
