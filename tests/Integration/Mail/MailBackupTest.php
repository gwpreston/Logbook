<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Mail;

use Logbook\Repository\NotificationSecretRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Mail\EmailServerAdmin;
use Logbook\Service\Mail\MailConfig;
use Logbook\Service\Mail\MailEncryption;
use Logbook\Service\Mail\SmtpServer;
use Logbook\Tests\Support\AppTestCase;
use Slim\Psr7\UploadedFile;
use ZipArchive;

/**
 * Backups and the email server (spec.md §7.11 *Backups*, Phase 36.1): the
 * `email.smtp` setting travels, `notification_secrets` never does; a
 * restored install asks for the password again, and the restore page
 * says so beforehand.
 */
final class MailBackupTest extends AppTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/logbook-mail-backup-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testThePasswordNeverTravelsAndARestoreAsksForItAgain(): void
    {
        $app = $this->createApp(['SESSION_SECRET' => str_repeat('a', 64), 'BACKUP_PATH' => $this->dir]);
        $browser = $this->signedIn($app);
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);
        $server = new SmtpServer('smtp.example.com', 587, MailEncryption::Tls, 'me', 'logbook@example.com');
        $this->service($app, EmailServerAdmin::class)->save($server, 'smtp-hunter2-pass', false, $owner);

        $backup = $this->dir . '/backup.zip';
        $manifest = $this->service($app, BackupService::class)->create($backup);
        self::assertSame(0, $manifest->rows('notification_secrets'));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($backup));
        self::assertFalse($zip->getFromName('database/notification_secrets.json'), 'never backed up');
        self::assertStringContainsString('email.smtp', (string) $zip->getFromName('database/settings.json'));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            self::assertStringNotContainsString('smtp-hunter2-pass', (string) $zip->getFromIndex($i));
        }
        $zip->close();

        // The restore page warns before anything changes.
        $copy = $this->dir . '/upload.zip';
        copy($backup, $copy);
        $response = $browser->post('/settings/backup/restore', [], [
            'backup' => new UploadedFile($copy, 'backup.zip', 'application/zip', (int) filesize($copy), UPLOAD_ERR_OK),
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString(
            'Backups never hold the email server&#039;s password.',
            (string) $browser->get($response->getHeaderLine('Location'))->getBody(),
        );

        $this->service($app, BackupService::class)->restore($backup);
        $restored = $this->service($app, MailConfig::class)->effective();
        self::assertNotNull($restored);
        self::assertSame('smtp.example.com', $restored->host, 'the settings travel');
        self::assertNull($this->service($app, NotificationSecretRepository::class)->find(null, 'smtp_password'));
        $state = $this->service($app, EmailServerAdmin::class)->passwordState();
        self::assertSame(['state' => 'unreadable', 'variable' => null], $state);

        $browser = $this->browserFor($app, 'owner');
        self::assertStringContainsString('Re-enter the password', (string) $browser->get('/settings/delivery')->getBody());
    }
}
