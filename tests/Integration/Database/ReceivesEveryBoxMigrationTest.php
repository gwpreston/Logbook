<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 37 (spec.md §7.11 *What each channel receives*; #268, #271): a
 * *Receives* saved under v3.3.0 with every box offered to its owner ticked
 * becomes all (null) on upgrading, for personal channels and for email; a
 * list that leaves a box out is kept. Rolling back changes nothing.
 */
final class ReceivesEveryBoxMigrationTest extends AppTestCase
{
    /** Phase 36.4's `notification_channels.categories`, the one before. */
    private const string BEFORE = '20261104100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testEveryBoxTickedBecomesAllAndTheRestIsKept(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $admin = $this->createOwner($app);
        $member = $this->createMember($app);
        Migrator::run('rollback', ['--target' => self::BEFORE]);

        $channels = $this->service($app, NotificationChannelRepository::class);
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $lists = [
            [$admin->id, 'ntfy', 'due,overdue,digest,price_alerts,job_failures'],
            [$admin->id, 'gotify', 'due,overdue,digest,price_alerts'],
            [$member->id, 'ntfy', 'due,overdue,digest,price_alerts'],
            [$member->id, 'gotify', 'due,digest'],
        ];
        foreach ($lists as [$userId, $kind, $categories]) {
            $channels->save($userId, $kind, ['url' => 'https://ntfy.example/t'], true, $now);
            $channels->setCategories($userId, $kind, $categories, $now);
        }
        $settings = $this->service($app, SettingRepository::class);
        $settings->save(
            'notifications',
            ['digest' => true, 'email_categories' => 'due,overdue,digest,price_alerts,job_failures'],
            SettingScope::User,
            $admin->id,
        );
        $settings->save('notifications', ['email_categories' => 'overdue'], SettingScope::User, $member->id);

        Migrator::run('migrate');
        self::assertNull($channels->find($admin->id, 'ntfy')?->categories, 'every box an admin has');
        self::assertSame(
            'due,overdue,digest,price_alerts',
            $channels->find($admin->id, 'gotify')?->categories,
            'an admin who left job failures out keeps the list',
        );
        self::assertNull($channels->find($member->id, 'ntfy')?->categories, 'every box a member has');
        self::assertSame('due,digest', $channels->find($member->id, 'gotify')?->categories);
        self::assertSame(
            ['digest' => true],
            $settings->find('notifications', SettingScope::User, $admin->id)?->value,
            'email: all, the rest kept',
        );
        self::assertSame(
            ['email_categories' => 'overdue'],
            $settings->find('notifications', SettingScope::User, $member->id)?->value,
        );

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        self::assertNull($channels->find($admin->id, 'ntfy')?->categories, 'nothing to undo');
    }
}
