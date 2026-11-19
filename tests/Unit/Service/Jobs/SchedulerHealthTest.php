<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Jobs;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Logbook\Repository\JobRunRepository;
use Logbook\Service\Jobs\SchedulerHealth;
use Logbook\Support\Cache\RequestReads;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cron line Settings → Jobs shows (spec.md §7.30): the install's folder stays bare when the
 * shell reads it as one word, and is quoted otherwise (Phase 54.21, K224, #646).
 */
final class SchedulerHealthTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function folders(): iterable
    {
        yield 'a plain path' => ['/var/www/logbook', '/var/www/logbook'];
        yield 'a space' => ['/Users/me/My Logbook', escapeshellarg('/Users/me/My Logbook')];
        yield 'a Windows path' => ['C:\Logbook', escapeshellarg('C:\Logbook')];
    }

    #[DataProvider('folders')]
    public function testTheFolderIsQuotedOnlyWhenItNeedsIt(string $root, string $shown): void
    {
        self::assertSame('*/15 * * * * cd ' . $shown . ' && php bin/run-scheduled-tasks.php', self::health($root)->cronLine());
    }

    private static function health(string $root): SchedulerHealth
    {
        // cronLine() reads only the settings; the repository is never asked.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $runs = new JobRunRepository($connection, new RequestReads());

        return new SchedulerHealth($runs, AppSettings::fromEnv(new Env([]), $root), new MutableClock(new DateTimeImmutable()));
    }
}
