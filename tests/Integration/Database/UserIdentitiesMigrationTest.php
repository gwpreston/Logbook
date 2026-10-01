<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;
use RuntimeException;

/**
 * Phase 23.1's identities migration: making `password_hash` nullable
 * rebuilds `users` on SQLite, which must keep every row that refers to a
 * user and the unique username. Rolling back is refused while a user has
 * no password, naming them.
 */
final class UserIdentitiesMigrationTest extends AppTestCase
{
    use CostFixtures;

    /** The migration before identities (Phase 22's trips). */
    private const string BEFORE = '20261014100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testUpAndDownKeepEveryRowThatRefersToAUser(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        Migrator::run('rollback', ['--target' => self::BEFORE]);
        Migrator::run('migrate');
        $this->createOwner($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '10000', '40', '55.00');
        $this->signedIn($app);
        $db = $this->connection($app);
        $counts = fn (): array => array_map(
            static fn (string $table): int => self::int($db->fetchOne('SELECT COUNT(*) FROM ' . $table)),
            ['users' => 'users', 'vehicles' => 'vehicles', 'fuel_entries' => 'fuel_entries', 'sessions' => 'sessions'],
        );
        $before = $counts();
        self::assertSame(1, $before['sessions']);

        Migrator::run('rollback', ['--target' => self::BEFORE]);
        self::assertSame($before, $counts(), 'rolling back deletes nothing');
        Migrator::run('migrate');
        self::assertSame($before, $counts(), 'upgrading deletes nothing');

        $db->insert('users', $this->userRow('nopassword', null));
        self::assertNull($db->fetchOne("SELECT password_hash FROM users WHERE username = 'nopassword'"));
        $this->expectException(UniqueConstraintViolationException::class);
        $db->insert('users', $this->userRow('owner', 'x'));
    }

    public function testRollingBackIsRefusedWhileAUserHasNoPassword(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $db = $this->connection($app);
        $db->insert('users', $this->userRow('sso-only', null));

        try {
            Migrator::run('rollback', ['--target' => self::BEFORE]);
            self::fail('rolling back with a user without a password must be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('sso-only', $e->getMessage());
        }
        self::assertTrue($db->createSchemaManager()->tablesExist(['user_identities']), 'the schema is intact');

        $db->update('users', ['password_hash' => 'x'], ['username' => 'sso-only']);
        $owner = self::int($db->fetchOne("SELECT id FROM users WHERE username = 'owner'"));
        $db->insert('invitations', [
            'token_hash' => str_repeat('a', 64), 'kind' => 'login', 'user_id' => null,
            'username' => 'owner', 'display_name' => 'Pat', 'is_admin' => false,
            'expires_at' => '2026-10-01 10:10:00', 'created_at' => '2026-10-01 10:00:00',
            'created_by' => $owner,
        ], ['is_admin' => 'boolean']);
        Migrator::run('rollback', ['--target' => self::BEFORE]);
        self::assertFalse($db->createSchemaManager()->tablesExist(['user_identities']));
        $logins = $db->fetchOne("SELECT COUNT(*) FROM invitations WHERE kind = 'login'");
        self::assertEquals(0, $logins, 'the old version cannot read them');
    }

    private static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @return array<string, string|null>
     */
    private function userRow(string $username, ?string $hash): array
    {
        return [
            'username' => $username, 'password_hash' => $hash, 'display_name' => 'Someone', 'locale' => 'en_GB',
            'timezone' => 'Europe/London', 'distance_unit' => 'mi', 'volume_unit' => 'l', 'consumption_unit' => 'mpg_uk',
            'depth_unit' => 'mm', 'currency' => 'GBP', 'theme' => 'system', 'accent' => 'blue',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ];
    }
}
