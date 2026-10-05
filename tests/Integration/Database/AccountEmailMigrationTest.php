<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Exception as DbalException;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 33.1's `users.email` (spec.md §6 User): upgrading moves each
 * reminder address out of the notification preferences onto the user,
 * confirmed (#163); rolling back moves it back and drops the new columns.
 */
final class AccountEmailMigrationTest extends AppTestCase
{
    /** The migration before Phase 33.1's (Phase 31's import sources). */
    private const string BEFORE = '20261029100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testTheReminderAddressMovesToTheUserAndBack(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $partner = $this->createMember($app);
        $quiet = $this->createMember($app, 'quiet');
        $this->withEmail($app, $owner, 'pat@example.com');
        $db = $this->connection($app);

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        try {
            $db->executeQuery('SELECT email FROM users WHERE 1 = 0');
            self::fail('rollback must drop users.email');
        } catch (DbalException) {
            // Gone.
        }
        $settings = $this->service($app, SettingRepository::class);
        $stored = $settings->find('notifications', SettingScope::User, $owner->id)?->value;
        self::assertIsArray($stored);
        self::assertSame('pat@example.com', $stored['email'], 'back in the preferences');
        self::assertTrue($stored['digest'] ?? null, 'the rest is kept');

        // An older version's preferences, as an upgrade finds them.
        $settings->save('notifications', ['channels' => ['email'], 'email' => ' Sam@Example.com ', 'digest' => false], SettingScope::User, $partner->id);
        $settings->save('notifications', ['email' => '', 'digest' => true], SettingScope::User, $quiet->id);

        Migrator::run('migrate');
        $users = $this->service($app, UserRepository::class);
        self::assertSame('pat@example.com', $users->find($owner->id)?->email);
        self::assertSame('sam@example.com', $users->find($partner->id)?->email, 'trimmed and lower-case, confirmed');
        self::assertNull($users->find($partner->id)->emailPending);
        self::assertNull($users->find($quiet->id)?->email);
        $moved = $settings->find('notifications', SettingScope::User, $partner->id)?->value;
        self::assertIsArray($moved);
        self::assertArrayNotHasKey('email', $moved, 'no longer in the preferences');
        self::assertSame(['email'], $moved['channels']);
    }
}
