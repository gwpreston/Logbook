<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Mail;

use Logbook\Kernel;
use Logbook\Support\Security\SessionSecretFile;
use Logbook\Tests\Support\AppTestCase;

/**
 * A `SESSION_SECRET` for a fresh Docker volume (spec.md §9, Phase 36.1,
 * #222): written only while the database has no users, never over an
 * existing file, never with `SESSION_SECRET` set, readable only by its
 * owner; and the app reads it through `SESSION_SECRET_FILE`.
 */
final class SessionSecretFileTest extends AppTestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/logbook-session-secret-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testAFreshDatabaseGetsASecretTheAppThenReads(): void
    {
        $app = $this->createApp(['SESSION_SECRET' => '', 'SESSION_SECRET_FILE' => $this->file]);
        $this->resetDatabase($app);

        self::assertSame(SessionSecretFile::GENERATED, $this->service($app, SessionSecretFile::class)->ensure());
        $secret = trim((string) file_get_contents($this->file));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4), 'readable only by its owner');

        $settings = Kernel::settings(['SESSION_SECRET' => '', 'SESSION_SECRET_FILE' => $this->file]);
        self::assertSame($secret, $settings->sessionSecret);
        $explicit = Kernel::settings(['SESSION_SECRET' => 'explicit', 'SESSION_SECRET_FILE' => $this->file]);
        self::assertSame('explicit', $explicit->sessionSecret);

        // Never replaced, even once users exist.
        $this->createOwner($app);
        self::assertSame(SessionSecretFile::EXISTS, $this->service($app, SessionSecretFile::class)->ensure());
        self::assertSame($secret, trim((string) file_get_contents($this->file)));
    }

    public function testAnInstallWithUsersIsNeverGivenOne(): void
    {
        $app = $this->createApp(['SESSION_SECRET' => '', 'SESSION_SECRET_FILE' => $this->file]);
        $this->resetDatabase($app);
        $this->createOwner($app);

        self::assertSame(SessionSecretFile::EXISTING_INSTALL, $this->service($app, SessionSecretFile::class)->ensure());
        self::assertFileDoesNotExist($this->file);
    }

    public function testNothingIsWrittenWithASecretOrWithoutAFile(): void
    {
        $app = $this->createApp(['SESSION_SECRET' => str_repeat('b', 64), 'SESSION_SECRET_FILE' => $this->file]);
        $this->resetDatabase($app);
        self::assertSame(SessionSecretFile::SET, $this->service($app, SessionSecretFile::class)->ensure());
        self::assertFileDoesNotExist($this->file);

        $bare = $this->createApp(['SESSION_SECRET' => '', 'SESSION_SECRET_FILE' => '']);
        self::assertSame(SessionSecretFile::NO_FILE, $this->service($bare, SessionSecretFile::class)->ensure());
        $missing = Kernel::settings(['SESSION_SECRET' => '', 'SESSION_SECRET_FILE' => $this->file . '-missing']);
        self::assertSame('', $missing->sessionSecret);
    }
}
