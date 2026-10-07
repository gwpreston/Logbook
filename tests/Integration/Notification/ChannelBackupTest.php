<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Notification;

use Logbook\Repository\NotificationChannelRepository;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Mail\NotificationSecrets;
use Logbook\Service\Notification\DeliveryResult;
use Logbook\Service\Notification\Notification;
use Logbook\Service\Notification\NotificationKind;
use Logbook\Service\Notification\Personal\BoundChannel;
use Logbook\Service\Notification\Personal\ChannelDefinition;
use Logbook\Service\Notification\Personal\ChannelSettings;
use Logbook\Service\Notification\Personal\PersonalSender;
use Logbook\Service\Notification\Recipient;
use Logbook\Tests\Support\ReminderTestCase;
use ZipArchive;

/**
 * Channel rows travel in backups without their secrets, so a restored
 * channel asks for its token again (spec.md §6 NotificationChannel); and a
 * channel's error never carries its secret.
 */
final class ChannelBackupTest extends ReminderTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/logbook-channel-backup-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testRowsAreBackedUpWithoutSecretsAndARestoreAsksForThemAgain(): void
    {
        $app = $this->createRecordingApp(self::CHANNELS + ['BACKUP_PATH' => $this->dir]);
        $this->signedIn($app);
        $owner = $this->owner($app);

        $backup = $this->dir . '/backup.zip';
        $manifest = $this->service($app, BackupService::class)->create($backup);
        self::assertSame(2, $manifest->rows('notification_channels'));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($backup));
        self::assertStringContainsString('ntfy.test', (string) $zip->getFromName('database/notification_channels.json'));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = (string) $zip->getFromIndex($i);
            self::assertStringNotContainsString('tk_secret', $content);
            self::assertStringNotContainsString('AppToken1', $content);
        }
        $zip->close();

        $this->service($app, BackupService::class)->restore($backup);

        self::assertNotNull($this->service($app, NotificationChannelRepository::class)->find($owner->id, 'gotify'));
        self::assertNull($this->service($app, NotificationSecrets::class)->open($owner->id, 'gotify.token'));
        $page = self::body($this->browserFor($app, 'owner')->get('/settings/notifications'));
        self::assertSame(2, substr_count($page, 'data-channel-status="needs_setup"'), 'ntfy and Gotify ask for their tokens');
        self::assertStringContainsString('data-secret-state="unreadable"', $page);
    }

    public function testAChannelsErrorNeverCarriesItsSecret(): void
    {
        $sender = new class implements PersonalSender {
            public function definition(): ChannelDefinition
            {
                return new ChannelDefinition('echo', 'Echo', 'send', 'notifications.webhook.hint', []);
            }

            public function destination(ChannelSettings $settings): ?string
            {
                return $settings->value('url');
            }

            public function validate(array $values): array
            {
                return [];
            }

            public function send(
                Notification $notification,
                Recipient $recipient,
                ChannelSettings $settings,
                bool $restricted,
            ): DeliveryResult {
                return DeliveryResult::failed('echo', 'POST https://echo.test/bot' . $settings->secret('token') . '/send: 401');
            }
        };

        $result = (new BoundChannel($sender, new ChannelSettings([], ['token' => 'bot-token-1234567890']), true))
            ->send(new Notification(NotificationKind::Reminders, 'T', 'M'), new Recipient(1, 'Pat'));

        self::assertFalse($result->delivered);
        self::assertStringNotContainsString('bot-token-1234567890', (string) $result->error);
        self::assertStringContainsString('401', (string) $result->error);
    }
}
