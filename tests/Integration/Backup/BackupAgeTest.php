<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\Backup\BackupKind;
use Logbook\Domain\Backup\BackupRecord;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Domain\User\User;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Service\Backup\BackupAge;
use Logbook\Service\Backup\BackupRecorder;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\Jobs\BackupJob;
use Logbook\Service\Jobs\BackupSchedule;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Jobs\JobSettings;
use Logbook\Service\Notification\Digest\DigestSummary;
use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Notification\WebhookPayload;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * How old is the last backup? (Phase 47, spec.md §7.13): the record, its
 * seeding and restore, the age in the viewer's time zone, the Backup page,
 * the dashboard banner and its *Dismiss*, the digest line and the API read.
 */
final class BackupAgeTest extends AppTestCase
{
    private const string NOW = '2026-10-13T12:00:00Z';

    private string $backupDir = '';

    protected function setUp(): void
    {
        if (!BackupService::isAvailable()) {
            self::markTestSkipped('PHP\'s zip extension is not installed.');
        }
        $this->backupDir = sys_get_temp_dir() . '/logbook-test-age-' . bin2hex(random_bytes(6));
        mkdir($this->backupDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
        parent::tearDown();
    }

    public function testEachWayOfBackingUpIsRecordedButNotAPreRestoreCopyOrAScheduledFilesDownload(): void
    {
        [$app, $clock] = $this->app();
        $browser = $this->signedIn($app);
        $recorder = $this->service($app, BackupRecorder::class);
        self::assertNull($recorder->last(), 'nothing yet');

        $download = $browser->get('/settings/backup/download');
        self::assertSame(200, $download->getStatusCode());
        self::assertEquals(
            new BackupRecord(new DateTimeImmutable(self::NOW), BackupKind::Download, strlen(self::body($download))),
            $recorder->last(),
        );

        $clock->set(new DateTimeImmutable('2026-10-13T13:00:00Z'));
        $job = $this->service($app, JobRegistry::class)->get(BackupJob::NAME);
        self::assertNotNull($job);
        $this->service($app, JobRunner::class)->run($job, JobTrigger::Manual);
        $files = glob($this->backupDir . '/logbook-scheduled-*.zip') ?: [];
        self::assertCount(1, $files);
        $scheduled = $recorder->last();
        self::assertEquals(
            new BackupRecord(new DateTimeImmutable('2026-10-13T13:00:00Z'), BackupKind::Scheduled, (int) filesize($files[0])),
            $scheduled,
        );

        // Downloading that file is a copy of a backup already recorded (#402).
        $clock->set(new DateTimeImmutable('2026-10-13T14:00:00Z'));
        self::assertSame(200, $browser->get('/settings/backup/files/' . basename($files[0]))->getStatusCode());
        self::assertEquals($scheduled, $recorder->last());

        // A restore writes a pre-restore copy, which never counts.
        $archive = $this->backupDir . '/to-restore.zip';
        $this->service($app, BackupService::class)->create($archive);
        $this->service($app, BackupService::class)->restore($archive);
        self::assertCount(1, glob($this->backupDir . '/pre-restore-*.zip') ?: []);
        self::assertEquals($scheduled, $recorder->last());
    }

    /**
     * #400: a restore brings the archive's record; the one from before is
     * kept when it is newer, and the archive's when that is.
     */
    public function testARestoreKeepsTheNewerRecord(): void
    {
        [$app] = $this->app();
        $this->signedIn($app);
        $backups = $this->service($app, BackupService::class);
        $recorder = $this->service($app, BackupRecorder::class);

        $this->storeRecord($app, '2026-09-01T08:00:00Z', 'cli');
        $older = $this->backupDir . '/older.zip';
        $backups->create($older);
        $this->storeRecord($app, '2026-10-10T08:00:00Z', 'download');
        $backups->restore($older);
        self::assertEquals(new DateTimeImmutable('2026-10-10T08:00:00Z'), $recorder->last()?->at, 'ours is newer');

        $newer = $this->backupDir . '/newer.zip';
        $this->storeRecord($app, '2026-10-12T08:00:00Z', 'scheduled');
        $backups->create($newer);
        $this->storeRecord($app, '2026-10-01T08:00:00Z', 'cli');
        $backups->restore($newer);
        self::assertSame(BackupKind::Scheduled, $recorder->last()?->kind, 'the archive\'s is newer');
    }

    /**
     * #401: the newest scheduled or command-line file seeds the record once;
     * pre-restore copies and other names never count.
     */
    public function testTheRecordIsSeededOnceFromBackupPath(): void
    {
        [$app] = $this->app();
        $this->resetDatabase($app);
        file_put_contents($this->backupDir . '/logbook-scheduled-20260901-030000.zip', 'abc');
        file_put_contents($this->backupDir . '/logbook-backup-2026-09-20-101500.zip', 'abcd');
        file_put_contents($this->backupDir . '/pre-restore-2026-10-01-000000.zip', 'abcde');
        file_put_contents($this->backupDir . '/my-own-2026-10-02.zip', 'abcdef');

        self::assertEquals(
            new BackupRecord(new DateTimeImmutable('2026-09-20T10:15:00Z'), BackupKind::Cli, 4),
            $this->service($app, BackupRecorder::class)->last(),
        );
        unlink($this->backupDir . '/logbook-backup-2026-09-20-101500.zip');
        self::assertSame(BackupKind::Cli, $this->service($app, BackupRecorder::class)->last()?->kind, 'read once');

        // With nothing to seed from, "no backup" is stored, and the folder isn't read again.
        $this->resetDatabase($app);
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        self::assertNull($this->service($app, BackupRecorder::class)->last());
        self::assertSame(['at' => null], $this->service($app, SettingRepository::class)->find(BackupRecorder::SETTING)?->value);
        file_put_contents($this->backupDir . '/logbook-scheduled-20261001-030000.zip', 'abc');
        self::assertNull($this->service($app, BackupRecorder::class)->last());
    }

    /**
     * The age is calendar days in the viewer's time zone, and the banner
     * shows once it is more than the threshold: 23:30 UTC on 12 September
     * is 13 September in London.
     */
    public function testTheAgeCountsCalendarDaysInTheViewersTimeZone(): void
    {
        [$app] = $this->app();
        $owner = $this->signedInOwner($app, $browser);
        $utcAdmin = $this->createMember($app, 'utc', $this->preferences('UTC'), isAdmin: true, displayName: 'Uma Utc');
        $this->storeRecord($app, '2026-09-12T23:30:00Z', 'download');
        $age = $this->service($app, BackupAge::class);

        $london = $age->for($owner);
        self::assertSame(30, $london->ageDays);
        self::assertFalse($london->warning, '30 days is not more than 30');
        self::assertStringNotContainsString('Your last backup was', self::body($browser->get('/')));

        $utc = $age->for($utcAdmin);
        self::assertSame(31, $utc->ageDays);
        self::assertTrue($utc->warning);
        $home = self::body($this->browserFor($app, 'utc')->get('/'));
        self::assertStringContainsString('Your last backup was 31 days ago.', $home);
        self::assertStringContainsString('href="/settings/backup/download">Back up now</a>', $home);
        self::assertStringNotContainsString('Turn on scheduled backups', $home, 'weekly since setup (#397)');

        $this->service($app, JobSettings::class)->setBackup(BackupSchedule::Off, 7);
        $home = self::body($this->browserFor($app, 'utc')->get('/'));
        self::assertStringContainsString('href="/settings/jobs#job-backup">Turn on scheduled backups</a>', $home);
    }

    public function testWithNoBackupTheWarningCountsFromTheFirstAccount(): void
    {
        [$app, $clock] = $this->app();
        $owner = $this->signedInOwner($app, $browser);
        $age = $this->service($app, BackupAge::class);
        self::assertFalse($age->for($owner)->warning);

        $clock->set(new DateTimeImmutable('2026-11-12T12:00:00Z'));
        self::assertFalse($age->for($owner)->warning, '30 days after setup');
        $clock->set(new DateTimeImmutable('2026-11-13T12:00:00Z'));
        $browser = $this->browserFor($app, 'owner');
        self::assertTrue($age->for($owner)->warning);
        self::assertNull($age->for($owner)->ageDays);
        self::assertStringContainsString('Logbook has never been backed up.', self::body($browser->get('/')));
        self::assertStringContainsString('No backup yet.', self::body($browser->get('/settings/backup')));

        $browser->get('/settings/backup/download');
        self::assertStringNotContainsString('never been backed up', self::body($browser->get('/')), 'the next backup ends it');
        self::assertStringContainsString(
            'Last backup today, 13 Nov 2026 (downloaded,',
            self::body($browser->get('/settings/backup')),
        );
    }

    public function testNeverAndBackingUpAnotherWayTurnTheWarningOff(): void
    {
        [$app, $clock] = $this->app();
        $owner = $this->signedInOwner($app, $browser);
        $clock->set(new DateTimeImmutable('2026-12-01T12:00:00Z'));
        $browser = $this->browserFor($app, 'owner');
        $age = $this->service($app, BackupAge::class);
        self::assertTrue($age->for($owner)->warning);
        self::assertSame(30, $age->for($owner)->warnAfterDays, 'the default (#396)');

        $browser->get('/settings/backup');
        self::assertSame(303, $browser->post('/settings/backup/warning', ['days' => 'never'])->getStatusCode());
        self::assertNull($age->for($owner)->warnAfterDays);
        self::assertFalse($age->for($owner)->warning);
        self::assertStringNotContainsString('never been backed up', self::body($browser->get('/')));

        $browser->get('/settings/backup');
        $browser->post('/settings/backup/warning', ['days' => '7', 'elsewhere' => '1']);
        $status = $age->for($owner);
        self::assertSame(7, $status->warnAfterDays);
        self::assertSame($owner->id, $status->elsewhere['by'] ?? null);
        self::assertFalse($status->warning);
        $page = self::body($browser->get('/settings/backup'));
        self::assertStringContainsString('Backups are handled outside Logbook (set by Pat Owner, 1 Dec 2026).', $page);

        // Saving again keeps who ticked it and when; unticking clears it.
        $clock->set(new DateTimeImmutable('2026-12-05T12:00:00Z'));
        $browser = $this->browserFor($app, 'owner');
        $browser->post('/settings/backup/warning', ['days' => '14', 'elsewhere' => '1']);
        self::assertEquals(new DateTimeImmutable('2026-12-01T12:00:00Z'), $age->for($owner)->elsewhere['at'] ?? null);
        $browser->get('/settings/backup');
        $browser->post('/settings/backup/warning', ['days' => '14']);
        self::assertNull($age->for($owner)->elsewhere);
        self::assertTrue($age->for($owner)->warning);

        $browser->get('/settings/backup');
        $refused = $browser->post('/settings/backup/warning', ['days' => '5']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Choose 7, 14, 30 or 90 days, or Never.', self::body($refused));
        self::assertSame(14, $age->for($owner)->warnAfterDays);
    }

    public function testDismissHidesTheBannerForSevenDays(): void
    {
        [$app, $clock] = $this->app();
        $this->signedInOwner($app, $browser);
        $clock->set(new DateTimeImmutable('2026-12-01T12:00:00Z'));
        $browser = $this->browserFor($app, 'owner');
        self::assertStringContainsString('data-admin-notice="backup_age"', self::body($browser->get('/')));

        self::assertSame(303, $browser->post('/notices/backup_age/dismiss')->getStatusCode());
        self::assertStringNotContainsString('data-admin-notice="backup_age"', self::body($browser->get('/')));
        $clock->set(new DateTimeImmutable('2026-12-08T11:59:00Z'));
        $browser = $this->browserFor($app, 'owner');
        self::assertStringNotContainsString('data-admin-notice="backup_age"', self::body($browser->get('/')));
        $clock->set(new DateTimeImmutable('2026-12-08T12:00:00Z'));
        $browser = $this->browserFor($app, 'owner');
        self::assertStringContainsString('data-admin-notice="backup_age"', self::body($browser->get('/')), 'back after 7 days');
    }

    public function testMembersNeverSeeItAndTheApiIsForAdminsOnly(): void
    {
        [$app, $clock] = $this->app();
        $owner = $this->signedInOwner($app, $browser);
        $member = $this->createMember($app);
        $clock->set(new DateTimeImmutable('2026-12-01T12:00:00Z'));

        $memberBrowser = $this->browserFor($app, 'partner');
        self::assertStringNotContainsString('backed up', self::body($memberBrowser->get('/')));
        self::assertSame(403, $memberBrowser->get('/settings/backup')->getStatusCode());

        $keys = $this->service($app, ApiKeyService::class);
        $refused = (new ApiClient($app, $keys->create($member, 'kuma', ApiScope::Read)->token))->get('/instance/backup');
        self::assertSame(403, $refused->getStatusCode());
        $problem = json_decode(self::body($refused), true);
        self::assertIsArray($problem);
        self::assertSame('forbidden', $problem['code'] ?? null);

        $api = new ApiClient($app, $keys->create($owner, 'kuma', ApiScope::Read)->token);
        $never = $api->get('/instance/backup');
        self::assertSame(200, $never->getStatusCode());
        self::assertSame([
            'last' => null,
            'age_days' => null,
            'warn_after_days' => 30,
            'handled_elsewhere' => false,
            'warning' => true,
        ], json_decode(self::body($never), true));

        $this->storeRecord($app, '2026-11-20T03:30:00Z', 'scheduled', 1234);
        self::assertSame([
            'last' => ['at' => '2026-11-20T03:30:00Z', 'kind' => 'scheduled', 'size' => 1234],
            'age_days' => 11,
            'warn_after_days' => 30,
            'handled_elsewhere' => false,
            'warning' => false,
        ], json_decode(self::body($api->get('/instance/backup')), true));
    }

    /**
     * #398: one line in an admin's digest while the banner would show,
     * right after what's due; it counts as content on its own and goes to
     * the webhook JSON. Members get none.
     */
    public function testTheDigestCarriesTheLineForAdminsOnly(): void
    {
        [$app, $clock] = $this->app();
        $owner = $this->signedInOwner($app, $browser);
        $member = $this->createMember($app);
        $clock->set(new DateTimeImmutable('2026-12-01T12:00:00Z'));
        $this->storeRecord($app, '2026-10-01T09:00:00Z', 'download');
        $summary = $this->service($app, DigestSummary::class);
        $today = new DateTimeImmutable('2026-12-01', new DateTimeZone('UTC'));

        $content = $summary->build($owner, [], $today, NotificationPreferences::forNewUser());
        self::assertSame(61, $content->backup?->days);
        self::assertFalse($content->isEmpty(), 'the line sends a digest on its own');
        self::assertSame($content->backup, $content->withoutAmounts()->backup, 'kept for WEBHOOK_URL');

        $digest = $this->service($app, NotificationComposer::class)->digest($owner, [], $today, [], $content);
        self::assertStringContainsString("\n\nYour last backup was 61 days ago.", $digest->message);
        $payload = WebhookPayload::of($digest, new Recipient($owner->id, $owner->displayName, username: $owner->username));
        self::assertSame(
            ['last_at' => '2026-10-01T09:00:00+00:00', 'days' => 61, 'display' => 'Your last backup was 61 days ago.'],
            $payload['backup'],
        );

        self::assertNull($summary->build($member, [], $today, NotificationPreferences::forNewUser())->backup);
    }

    public function testNewInstallsBackUpWeeklyAndOlderOnesStayOff(): void
    {
        [$app] = $this->app();
        $this->signedIn($app);
        $jobs = $this->service($app, JobSettings::class);
        self::assertSame(BackupSchedule::Weekly, $jobs->backupSchedule());
        self::assertSame(7, $jobs->backupKeep());

        $this->service($app, SettingRepository::class)->delete(JobSettings::BACKUP);
        self::assertSame(BackupSchedule::Off, $jobs->backupSchedule(), 'an install from before has no setting');
    }

    /**
     * @return array{App<ContainerInterface>, MutableClock}
     */
    private function app(): array
    {
        $app = $this->createApp(['BACKUP_PATH' => $this->backupDir]);
        $clock = $this->pinClock($app, self::NOW);

        return [$app, $clock];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param-out TestBrowser $browser
     */
    private function signedInOwner(App $app, ?TestBrowser &$browser): User
    {
        $browser = $this->signedIn($app);
        $owner = $this->service($app, UserRepository::class)->findByUsername('owner');
        self::assertNotNull($owner);

        return $owner;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function storeRecord(App $app, string $at, string $kind, int $size = 100): void
    {
        $this->service($app, SettingRepository::class)->save(BackupRecorder::SETTING, [
            'at' => $at,
            'kind' => $kind,
            'size' => $size,
        ]);
    }

    private function preferences(string $timezone): DisplayPreferences
    {
        $preset = UnitPreset::Uk;

        return new DisplayPreferences('en_GB', $timezone, $preset->distance(), $preset->volume(), $preset->consumption(), 'GBP');
    }
}
