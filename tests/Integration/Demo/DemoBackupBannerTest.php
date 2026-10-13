<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Demo;

use DateTimeImmutable;
use Logbook\Repository\UserRepository;
use Logbook\Service\Backup\BackupBanner;
use Logbook\Tests\Support\MutableClock;

/**
 * Phase 47 (spec.md §7.13, §7.36): the demo is never backed up, and never
 * says so: no backup banner, however old the demo is.
 */
final class DemoBackupBannerTest extends DemoTestCase
{
    public function testTheDemoNeverShowsTheBackupBanner(): void
    {
        $app = $this->demoApp();
        $clock = $this->pinClock($app, '2026-10-13T12:00:00Z');
        self::assertInstanceOf(MutableClock::class, $clock);
        $this->seedDemo($app);
        $clock->set(new DateTimeImmutable('2027-03-01T12:00:00Z'));

        foreach ($this->service($app, UserRepository::class)->listAll() as $user) {
            self::assertNull($this->service($app, BackupBanner::class)->for($user), $user->username);
        }
        $home = self::body($this->demoBrowser($app)->get('/'));
        self::assertStringNotContainsString('backed up', $home);
        self::assertStringNotContainsString('data-admin-notice="backup_age"', $home);
    }
}
