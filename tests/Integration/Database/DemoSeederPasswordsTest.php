<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Logbook\Repository\SessionRepository;
use Logbook\Repository\UserRepository;
use Logbook\Support\Security\PasswordHasher;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;
use Logbook\Tests\Support\TestBrowser;

/**
 * The sample users' passwords are new on every run (spec.md §10
 * *Development stack*, Phase 33.1): taken from DEMO_PASSWORD and
 * PARTNER_PASSWORD, set again on a database that already has the sample
 * users, and hashed by PasswordHasher. The users have confirmed addresses.
 */
final class DemoSeederPasswordsTest extends AppTestCase
{
    protected function tearDown(): void
    {
        putenv('DEMO_PASSWORD');
        putenv('PARTNER_PASSWORD');
        parent::tearDown();
    }

    public function testEachRunSetsTheGivenPasswords(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        putenv('DEMO_PASSWORD=first-demo-password-1');
        putenv('PARTNER_PASSWORD=first-partner-pass-1');
        $output = Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        self::assertStringContainsString('first-demo-password-1', $output);

        $users = $this->service($app, UserRepository::class);
        $demo = $users->findByUsername('demo');
        self::assertNotNull($demo);
        self::assertSame('demo@example.test', $demo->email, 'confirmed, for trying a reset in Mailpit');
        self::assertSame('partner@example.test', $users->findByUsername('partner')?->email);
        $hasher = new PasswordHasher();
        self::assertTrue($hasher->verify('first-demo-password-1', (string) $demo->passwordHash));
        self::assertFalse($hasher->needsRehash((string) $demo->passwordHash), 'the app’s own Argon2id options');

        $signedIn = new TestBrowser($app);
        $signedIn->get('/login');
        self::assertSame(303, $signedIn->post('/login', ['username' => 'demo', 'password' => 'first-demo-password-1'])->getStatusCode());
        $vehicles = (int) $this->connection($app)->fetchOne('SELECT COUNT(*) FROM vehicles');

        putenv('DEMO_PASSWORD=second-demo-password');
        putenv('PARTNER_PASSWORD=second-partner-pass');
        $again = Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        self::assertStringContainsString('new passwords set', $again);
        self::assertSame($vehicles, (int) $this->connection($app)->fetchOne('SELECT COUNT(*) FROM vehicles'), 'no second garage');

        $demo = $users->findByUsername('demo');
        self::assertNotNull($demo);
        self::assertTrue($hasher->verify('second-demo-password', (string) $demo->passwordHash));
        self::assertTrue($hasher->verify('second-partner-pass', (string) $users->findByUsername('partner')?->passwordHash));
        self::assertArrayNotHasKey($demo->id, $this->service($app, SessionRepository::class)->lastActivityByUser(), 'sessions end');
        self::assertStringContainsString('/login', $signedIn->get('/garage')->getHeaderLine('Location'));
    }

    public function testRunDirectlyItMakesUpPasswordsAndPrintsThem(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $output = Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);

        self::assertSame(1, preg_match('/as "demo" with "([A-Za-z0-9]{20})"/', $output, $m), $output);
        self::assertDoesNotMatchRegularExpression('/[0O1lI]/', $m[1] ?? '', 'an unambiguous alphabet');
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertTrue((new PasswordHasher())->verify($m[1] ?? '', (string) $demo?->passwordHash));
    }
}
