<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\User\User;
use Logbook\Repository\NotificationChannelRepository;
use Logbook\Repository\NotificationSecretRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The Phase 36.2 migration (spec.md §7.11 *The server's variables*, #227,
 * #230, #247): personal ntfy topics and Gotify tokens move into channel
 * rows, the server's NTFY_* and GOTIFY_* are imported once, a token is
 * sealed so the app can open it (and never copied in the clear without a
 * key), and rolling back puts everything back.
 */
final class NotificationChannelsMigrationTest extends AppTestCase
{
    private const string BEFORE = '20261102100000';
    private const string SECRET = 'migration-test-secret-0123456789abcdef0123456789abcdef';
    private const array VARIABLES = [
        'NTFY_URL' => 'https://ntfy.test/garage',
        'NTFY_TOKEN' => 'tk_server',
        'GOTIFY_URL' => 'https://gotify.test/',
        'GOTIFY_TOKEN' => 'ServerAppToken',
        'GOTIFY_PRIORITY' => '7',
    ];

    protected function tearDown(): void
    {
        foreach ([...array_keys(self::VARIABLES), 'SESSION_SECRET', 'SESSION_SECRET_FILE'] as $name) {
            putenv($name);
        }
        // Always leave the schema fully migrated for the rest of the suite.
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testPersonalValuesMoveAndTheServersAreImportedOnce(): void
    {
        $app = $this->createApp(['SESSION_SECRET' => self::SECRET]);
        [$owner, $sam, $lee, $kim] = $this->usersBeforeTheUpgrade($app);
        $this->environment(self::SECRET);

        Migrator::run('migrate');

        $channels = $this->service($app, NotificationChannelRepository::class);
        $secrets = $this->service($app, NotificationSecrets::class);

        // The owner (an admin) had ntfy and Gotify switched off and no topic of their own: the server's, imported, still off.
        $ntfy = $channels->find($owner->id, 'ntfy');
        self::assertNotNull($ntfy);
        self::assertFalse($ntfy->enabled, 'their choice is kept');
        self::assertSame(['url' => 'https://ntfy.test/garage'], $ntfy->values());
        self::assertSame(true, $ntfy->settings['_imported'] ?? null);
        self::assertSame('tk_server', $secrets->open($owner->id, 'ntfy.token'), 'the app opens what the migration sealed');
        $gotify = $channels->find($owner->id, 'gotify');
        self::assertNotNull($gotify);
        self::assertFalse($gotify->enabled);
        self::assertEquals(['priority' => 7, 'url' => 'https://gotify.test'], $gotify->values(), 'MySQL sorts JSON keys');
        self::assertSame('ServerAppToken', $secrets->open($owner->id, 'gotify.token'));

        // Sam (a member) had their own topic on the server's ntfy, which was sent with NTFY_TOKEN, and their own Gotify token.
        $samNtfy = $channels->find($sam->id, 'ntfy');
        self::assertNotNull($samNtfy);
        self::assertTrue($samNtfy->enabled, 'never chosen: every channel');
        self::assertSame('https://ntfy.test/sam', $samNtfy->value('url'));
        self::assertSame('tk_server', $secrets->open($sam->id, 'ntfy.token'));
        self::assertArrayNotHasKey('_imported', $samNtfy->settings);
        self::assertSame('SamsOwnToken', $secrets->open($sam->id, 'gotify.token'));
        self::assertSame('https://gotify.test', $channels->find($sam->id, 'gotify')?->value('url'));
        self::assertEquals(['channels' => null, 'digest' => true], $this->preferences($app, $sam), 'the values left the setting');

        // Lee (a member) had nothing: a member never gets the server's.
        self::assertSame([], $channels->forUser($lee->id));

        // Kim (an admin) had a topic on another server: no NTFY_TOKEN there; the server's Gotify imported.
        $kimNtfy = $channels->find($kim->id, 'ntfy');
        self::assertNotNull($kimNtfy);
        self::assertSame('https://push.example/kim', $kimNtfy->value('url'));
        self::assertSame([], $kimNtfy->secretFields());
        self::assertSame('ServerAppToken', $secrets->open($kim->id, 'gotify.token'));
        $kept = $this->preferences($app, $kim)['channels'] ?? null;
        self::assertSame(['email', 'webhook'], $kept, 'only the server\'s keys stay');

        // Nothing is stored in the clear.
        $stored = $this->service($app, NotificationSecretRepository::class)->all();
        self::assertCount(5, $stored, "the owner's two, Sam's two and Kim's Gotify");
        foreach ($stored as $value) {
            self::assertStringStartsWith('v1:', $value);
        }

        // Rolling back puts the personal values back and drops the server's import.
        Migrator::run('rollback', ['--target' => self::BEFORE]);
        $samBack = $this->preferences($app, $sam);
        self::assertSame('https://ntfy.test/sam', $samBack['ntfy_url'] ?? null);
        self::assertSame('SamsOwnToken', $samBack['gotify_token'] ?? null);
        self::assertSame(['email', 'webhook'], $this->preferences($app, $owner)['channels'] ?? null);
        self::assertSame('https://push.example/kim', $this->preferences($app, $kim)['ntfy_url'] ?? null);
        self::assertArrayNotHasKey('gotify_token', $this->preferences($app, $kim), 'the imported token goes with its variable');
        $kimChannels = $this->preferences($app, $kim)['channels'] ?? null;
        self::assertIsArray($kimChannels);
        self::assertContains('gotify', $kimChannels, 'an imported channel that was on stays on');
        self::assertSame([], $this->service($app, NotificationSecretRepository::class)->all());
        self::assertFalse($this->connection($app)->createSchemaManager()->tablesExist(['notification_channels']));
    }

    public function testWithoutAKeyNothingIsCopiedInTheClear(): void
    {
        $app = $this->createApp(['SESSION_SECRET' => self::SECRET]);
        [$owner, $sam] = $this->usersBeforeTheUpgrade($app);
        $this->environment('');

        Migrator::run('migrate');

        self::assertSame([], $this->service($app, NotificationSecretRepository::class)->all());
        $channels = $this->service($app, NotificationChannelRepository::class);
        $gotify = $channels->find($sam->id, 'gotify');
        self::assertNotNull($gotify);
        self::assertSame(['token'], $gotify->secretFields(), 'Needs setup until the token is entered again');
        self::assertSame('SamsOwnToken', $this->preferences($app, $sam)['gotify_token'] ?? null, 'left where it was');
        self::assertSame(['token'], $channels->find($owner->id, 'ntfy')?->secretFields());
        $raw = $this->connection($app)->fetchAllAssociative('SELECT settings FROM notification_channels');
        self::assertStringNotContainsString('SamsOwnToken', (string) json_encode($raw));
        self::assertStringNotContainsString('ServerAppToken', (string) json_encode($raw));
    }

    /**
     * Back to before Phase 36.2, with four users as they were.
     *
     * @param App<ContainerInterface> $app
     * @return array{User, User, User, User} owner, sam, lee, kim
     */
    private function usersBeforeTheUpgrade(App $app): array
    {
        $this->resetDatabase($app);
        Migrator::run('rollback', ['--target' => self::BEFORE]);
        $owner = $this->createOwner($app);
        $sam = $this->createMember($app, 'sam');
        $lee = $this->createMember($app, 'lee');
        $kim = $this->createMember($app, 'kim', isAdmin: true);

        $this->saveOld($app, $owner, ['channels' => ['email', 'webhook'], 'digest' => false]);
        $this->saveOld($app, $sam, [
            'channels' => null,
            'digest' => true,
            'ntfy_url' => 'https://ntfy.test/sam',
            'gotify_token' => 'SamsOwnToken',
        ]);
        $this->service($app, SettingRepository::class)->delete('notifications', SettingScope::User, $lee->id);
        $this->saveOld($app, $kim, [
            'channels' => ['email', 'ntfy', 'gotify', 'webhook'],
            'ntfy_url' => 'https://push.example/kim/',
        ]);

        return [$owner, $sam, $lee, $kim];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $value
     */
    private function saveOld(App $app, User $user, array $value): void
    {
        $this->service($app, SettingRepository::class)->save('notifications', $value, SettingScope::User, $user->id);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, mixed>
     */
    private function preferences(App $app, User $user): array
    {
        $value = $this->service($app, SettingRepository::class)->find('notifications', SettingScope::User, $user->id)?->value;
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** What phinx.php hands the migration (it reads the process environment). */
    private function environment(string $secret): void
    {
        foreach (self::VARIABLES as $name => $value) {
            putenv($name . '=' . $value);
        }
        putenv('SESSION_SECRET=' . $secret);
        putenv('SESSION_SECRET_FILE=');
    }
}
