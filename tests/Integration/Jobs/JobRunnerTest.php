<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Jobs;

use DateTimeImmutable;
use Logbook\Domain\Job\JobRun;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Domain\User\InvitationKind;
use Logbook\Kernel;
use Logbook\Repository\InvitationRepository;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Jobs\AdminNotices;
use Logbook\Service\Jobs\BackupJob;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\QuietHours;
use Logbook\Service\Jobs\BackupSchedule;
use Logbook\Service\Jobs\CleanupJob;
use Logbook\Service\Jobs\Job;
use Logbook\Service\Jobs\JobFailureAlerts;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Jobs\JobSettings;
use Logbook\Service\Jobs\RemindersJob;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Tests\Support\ReminderTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Jobs and the runner (spec.md §5 *Jobs*, §7.30): runs recorded per
 * trigger, per-job locks, interrupted runs, cleanup retention, scheduled
 * backups and failure alerts.
 */
final class JobRunnerTest extends ReminderTestCase
{
    private const string NOW = '2026-09-27T10:00:00Z';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testAPassRecordsEachDueJobWithItsTrigger(): void
    {
        $app = $this->createRecordingApp();
        $clock = $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');

        $summary = $this->service($app, ScheduledTasks::class)->run(JobTrigger::Cron);
        self::assertSame(1, $summary->remindersSent);
        self::assertSame(['reminders' => 'cron', 'digest' => 'cron', 'cleanup' => 'cron'], $this->runsByJob($app));
        $reminders = $this->runs($app)->latest('reminders');
        self::assertNotNull($reminders);
        self::assertSame(JobStatus::Ok, $reminders->status);
        self::assertSame('Checked 1 account; sent 1 reminder', $reminders->summary);
        self::assertNull($reminders->userId);
        self::assertStringContainsString(
            'Notification (reminders) sent to user',
            $reminders->output,
            'the services\' lines are captured',
        );
        self::assertCount(1, $this->mail->sent);

        // Cleanup is hourly; backups are off.
        $clock->set(new DateTimeImmutable('2026-09-27T10:15:00Z'));
        $this->service($app, ScheduledTasks::class)->run(JobTrigger::Docker);
        self::assertSame(['reminders' => 'docker', 'digest' => 'docker', 'cleanup' => 'cron'], $this->runsByJob($app));
        self::assertCount(1, $this->mail->sent, 'never sent twice');

        $clock->set(new DateTimeImmutable('2026-09-27T11:00:00Z'));
        $this->service($app, ScheduledTasks::class)->run(JobTrigger::Cron);
        self::assertSame('cron', $this->runsByJob($app)['cleanup']);
        self::assertCount(2, array_filter($this->runs($app)->recent(50), static fn (JobRun $r): bool => $r->job === 'cleanup'));
    }

    public function testAManualRunWhileCronHoldsTheJobIsSkippedAndSendsNothing(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $owner = $this->owner($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');
        $holder = $this->runs($app)->start('reminders', JobTrigger::Cron, null, new DateTimeImmutable(self::NOW));

        $lock = $this->holdLock('locks/job-reminders.lock');
        try {
            $run = $this->runReminders($app, $owner->id);
        } finally {
            $this->release($lock);
        }

        self::assertSame(JobStatus::SkippedLocked, $run->status);
        self::assertSame($owner->id, $run->userId);
        self::assertSame('Already running (run #' . $holder . ', cron).', $run->summary);
        self::assertSame($holder, $this->runs($app)->holderOf($run)?->id);
        self::assertCount(0, $this->mail->sent, 'nothing sent while locked');

        // The holder is recent, so it is left running; the manual run now does the work, once.
        $run = $this->runReminders($app, $owner->id);
        self::assertSame(JobStatus::Ok, $run->status);
        self::assertSame(JobStatus::Running, $this->runs($app)->find($holder)?->status);
        self::assertCount(1, $this->mail->sent);
        $this->runReminders($app, $owner->id);
        self::assertCount(1, $this->mail->sent, 'the reminder claims still stop a second send');
    }

    public function testARunLeftRunningForAnHourIsMarkedInterrupted(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $dead = $this->runs($app)->start('reminders', JobTrigger::Cron, null, new DateTimeImmutable('2026-09-27T08:59:00Z'));
        $recent = $this->runs($app)->start('digest', JobTrigger::Cron, null, new DateTimeImmutable('2026-09-27T09:30:00Z'));

        $this->runReminders($app, null);

        $run = $this->runs($app)->find($dead);
        self::assertSame(JobStatus::Interrupted, $run?->status);
        self::assertNotNull($run->finishedAt);
        self::assertSame(JobStatus::Running, $this->runs($app)->find($recent)?->status, 'another job, and under an hour');
    }

    public function testCleanupDeletesClosedInvitationsAfter90DaysAndPrunesRuns(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $owner = $this->owner($app);
        $invitations = $this->service($app, InvitationRepository::class);
        $at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable($time);
        $link = static fn (string $name, string $expires, string $created) => $invitations->insert(
            hash('sha256', $name),
            InvitationKind::Invite,
            $owner->id,
            null,
            $name,
            ucfirst($name),
            false,
            $at($expires),
            $at($created),
        );
        $oldUsed = $link('oldused', '2026-05-10T00:00:00Z', '2026-05-01T00:00:00Z');
        $invitations->markUsed($oldUsed, $at('2026-05-02T00:00:00Z'));
        $recentUsed = $link('recentused', '2026-05-10T00:00:00Z', '2026-05-01T00:00:00Z');
        $invitations->markUsed($recentUsed, $at('2026-07-01T00:00:00Z'));
        $oldRevoked = $link('oldrevoked', '2026-09-30T00:00:00Z', '2026-05-01T00:00:00Z');
        $invitations->revoke($oldRevoked, $at('2026-06-01T00:00:00Z'));
        $oldExpired = $link('oldexpired', '2026-06-20T00:00:00Z', '2026-06-13T00:00:00Z');
        $recentExpired = $link('recentexpired', '2026-07-20T00:00:00Z', '2026-07-13T00:00:00Z');
        $open = $link('open', '2026-10-01T00:00:00Z', '2026-09-24T00:00:00Z');

        // Runs: 60 of reminders this week, one of digest 100 days ago.
        $runs = $this->runs($app);
        for ($i = 0; $i < 60; $i++) {
            $id = $runs->start('reminders', JobTrigger::Cron, null, $at('2026-09-20T00:00:00Z')->modify("+$i minutes"));
            $runs->finish($id, JobStatus::Ok, 'ok', '', $at('2026-09-20T00:00:00Z')->modify("+$i minutes"));
        }
        $old = $runs->start('digest', JobTrigger::Cron, null, $at('2026-06-19T00:00:00Z'));
        $runs->finish($old, JobStatus::Ok, 'ok', '', $at('2026-06-19T00:00:00Z'));

        $run = $this->runJob($app, CleanupJob::class);

        self::assertSame(JobStatus::Ok, $run->status);
        self::assertSame(3, $run->counts['invitations']);
        self::assertSame(11, $run->counts['runs'], 'ten past the newest 50, and the one past 90 days');
        self::assertSame('Deleted 3 invitations, 11 job runs', $run->summary);
        foreach ([$oldUsed, $oldRevoked, $oldExpired] as $gone) {
            self::assertNull($invitations->find($gone));
        }
        foreach ([$recentUsed, $recentExpired, $open] as $kept) {
            self::assertNotNull($invitations->find($kept));
        }
        self::assertNull($runs->find($old));
        self::assertSame(3600, $this->service($app, CleanupJob::class)->interval(), 'hourly (#108)');
    }

    public function testScheduledBackupsRunDailyOrWeeklyAndKeepOnlyTheirOwnFiles(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('needs the zip extension');
        }
        $dir = $this->tempDir();
        $app = $this->createRecordingApp(['BACKUP_PATH' => $dir] + self::CHANNELS);
        $clock = $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        touch($dir . '/logbook-backup-2026-01-01-000000.zip');
        touch($dir . '/pre-restore-20260101-000000.zip');
        $settings = $this->service($app, JobSettings::class);
        $tasks = $this->service($app, ScheduledTasks::class);

        $tasks->run(JobTrigger::Cron);
        self::assertSame([], $this->scheduledFiles($dir), 'off by default');

        $settings->setBackup(BackupSchedule::Daily, 2);
        $tasks->run(JobTrigger::Cron);
        self::assertSame(['logbook-scheduled-20260927-100000.zip'], $this->scheduledFiles($dir));
        $backup = $this->runs($app)->latest('backup');
        self::assertSame(JobStatus::Ok, $backup?->status);
        self::assertMatchesRegularExpression(
            '/^Wrote logbook-scheduled-20260927-100000\.zip \([0-9.]+ [kKM]?B\)$/',
            (string) $backup->summary,
        );

        $clock->set(new DateTimeImmutable('2026-09-27T20:00:00Z'));
        $tasks->run(JobTrigger::Cron);
        self::assertCount(1, $this->scheduledFiles($dir), 'not due again the same day');

        foreach (['2026-09-28T10:00:00Z', '2026-09-29T10:00:00Z'] as $day) {
            $clock->set(new DateTimeImmutable($day));
            $tasks->run(JobTrigger::Cron);
        }
        self::assertSame(
            ['logbook-scheduled-20260928-100000.zip', 'logbook-scheduled-20260929-100000.zip'],
            $this->scheduledFiles($dir),
            'keeps the newest two',
        );
        self::assertStringEndsWith('; deleted 1 old backup', (string) $this->runs($app)->latest('backup')?->summary);
        self::assertFileExists($dir . '/logbook-backup-2026-01-01-000000.zip', 'the owner\'s own backup stays');
        self::assertFileExists($dir . '/pre-restore-20260101-000000.zip', 'and the pre-restore one');

        $settings->setBackup(BackupSchedule::Weekly, 7);
        $clock->set(new DateTimeImmutable('2026-10-05T09:00:00Z'));
        $tasks->run(JobTrigger::Cron);
        self::assertCount(2, $this->scheduledFiles($dir), 'six days later: not due weekly');
        $clock->set(new DateTimeImmutable('2026-10-06T10:00:00Z'));
        $tasks->run(JobTrigger::Cron);
        self::assertCount(3, $this->scheduledFiles($dir), 'seven days later');
    }

    public function testTwoFailuresInARowAlertEachAdminOncePerStreak(): void
    {
        $dir = $this->tempDir();
        $blocker = $dir . '/not-a-directory';
        file_put_contents($blocker, 'x');
        $app = $this->createRecordingApp(['BACKUP_PATH' => $blocker . '/backups'] + self::CHANNELS);
        $clock = $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->ownerFromBefore21($app);
        $owner = $this->owner($app);
        $this->createMember($app);
        $notices = $this->service($app, AdminNotices::class);

        $first = $this->runJob($app, BackupJob::class, $owner->id);
        self::assertSame(JobStatus::Failed, $first->status);
        self::assertStringContainsString('BACKUP_PATH', (string) $first->summary);
        self::assertCount(0, $this->mail->sent, 'one failure is not a streak');

        $second = $this->runJob($app, BackupJob::class);
        self::assertCount(1, $this->mail->sent, 'the admin, through their channels; not the member');
        self::assertSame('Logbook: the Backup job failed twice in a row', $this->mail->sent[0]->getSubject());
        self::assertStringContainsString('/settings/jobs/runs/' . $second->id, (string) $this->mail->sent[0]->getTextBody());
        $keys = array_map(static fn ($n): string => $n->key, $notices->for($owner));
        self::assertContains('job_failed.backup', $keys);
        $member = $this->service($app, UserRepository::class)->findByUsername('partner');
        self::assertNotNull($member);
        self::assertSame([], $notices->for($member), 'members see no notices');

        $this->runJob($app, BackupJob::class);
        self::assertCount(1, $this->mail->sent, 'once per streak');

        // A run that works ends the streak; two more failures are a new one.
        $this->service($app, JobRunRepository::class)->finish(
            $this->runs($app)->start('backup', JobTrigger::Manual, null, $clock->now()),
            JobStatus::Ok,
            'ok',
            '',
            $clock->now(),
        );
        $this->service($app, JobFailureAlerts::class)->afterRun(
            $this->runs($app)->latest('backup') ?? throw new \LogicException('run'),
        );
        self::assertNotContains('job_failed.backup', array_map(static fn ($n): string => $n->key, $notices->for($owner)));
        $this->runJob($app, BackupJob::class);
        $this->runJob($app, BackupJob::class);
        self::assertCount(2, $this->mail->sent);
    }

    /**
     * Phase 36.4 (spec.md §7.11, §7.30): an admin in quiet hours is sent a
     * failed job after they end, if it is still failing; a streak that
     * ended meanwhile is dropped.
     */
    public function testAFailedJobIsHeldThroughQuietHours(): void
    {
        $dir = $this->tempDir();
        $blocker = $dir . '/not-a-directory';
        file_put_contents($blocker, 'x');
        $app = $this->createRecordingApp(['BACKUP_PATH' => $blocker . '/backups'] + self::CHANNELS);
        $clock = $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->ownerFromBefore21($app);
        $owner = $this->owner($app);
        $store = $this->service($app, ReminderSettingsStore::class);
        // 09:30 to 12:30 holds 10:00 UTC whether the owner is on UTC or British time.
        $store->saveNotificationPreferences($owner->id, $store->notificationPreferences($owner->id)
            ->withQuiet(QuietHours::of('09:30', '12:30')));

        $this->runJob($app, BackupJob::class);
        $this->runJob($app, BackupJob::class);
        self::assertSame([], $this->mail->sent, 'held');

        $clock->set(new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $this->runJob($app, CleanupJob::class);
        self::assertCount(1, $this->mail->sent, 'sent after any run once quiet hours are over');
        self::assertSame('Logbook: the Backup job failed twice in a row', $this->mail->sent[0]->getSubject());
        $this->runJob($app, CleanupJob::class);
        self::assertCount(1, $this->mail->sent, 'once');

        // A new streak in quiet hours that ends before they do is dropped.
        $this->service($app, JobRunRepository::class)->finish(
            $this->runs($app)->start('backup', JobTrigger::Manual, null, $clock->now()),
            JobStatus::Ok,
            'ok',
            '',
            $clock->now(),
        );
        $this->service($app, JobFailureAlerts::class)->afterRun(
            $this->runs($app)->latest('backup') ?? throw new \LogicException('run'),
        );
        $clock->set(new DateTimeImmutable('2026-09-28T10:00:00Z'));
        $this->runJob($app, BackupJob::class);
        $this->runJob($app, BackupJob::class);
        $this->service($app, JobRunRepository::class)->finish(
            $this->runs($app)->start('backup', JobTrigger::Manual, null, $clock->now()),
            JobStatus::Ok,
            'ok',
            '',
            $clock->now(),
        );
        $clock->set(new DateTimeImmutable('2026-09-28T13:00:00Z'));
        $this->runJob($app, CleanupJob::class);
        self::assertCount(1, $this->mail->sent, 'the streak ended: nothing to send');

        // Several held at once go as one message (#253).
        $failed = array_values(array_filter(
            $this->runs($app)->recent(100),
            static fn (JobRun $r): bool => $r->job === 'backup' && $r->status === JobStatus::Failed,
        ));
        $combined = $this->service($app, NotificationComposer::class)->jobsFailed($owner, array_slice($failed, 0, 2));
        self::assertSame('Logbook: 2 jobs failed twice in a row', $combined->title);
        self::assertStringContainsString('• Backup: ', $combined->message);
    }

    public function testRunJobFromTheCommandLine(): void
    {
        $app = $this->createRecordingApp();
        $this->signedIn($app);
        $script = Kernel::rootDir() . '/bin/run-job.php';

        [$code, $output] = self::execute([PHP_BINARY, $script, '--list']);
        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/^reminders\s+every pass$/m', $output);
        self::assertMatchesRegularExpression('/^cleanup\s+every 3600 s$/m', $output);
        self::assertMatchesRegularExpression('/^backup\s+manual only$/m', $output);

        [$code, $output] = self::execute([PHP_BINARY, $script, 'nope']);
        self::assertSame(3, $code);
        self::assertStringContainsString('Usage:', $output);

        [$code, $output] = self::execute([PHP_BINARY, $script, 'cleanup']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Finished cleanup: ok.', $output, 'the stored lines, printed as they come');
        self::assertStringEndsWith("ok: Nothing to delete\n", $output);
        $run = $this->runs($app)->latest('cleanup');
        self::assertSame(JobTrigger::Manual, $run?->trigger);
        self::assertNull($run->userId, 'no user from the command line');

        $lock = $this->holdLock('locks/job-cleanup.lock');
        try {
            [$code] = self::execute([PHP_BINARY, $script, 'cleanup']);
        } finally {
            $this->release($lock);
        }
        self::assertSame(2, $code, 'already running');
    }

    /**
     * @param list<string> $command
     * @return array{int, string}
     */
    private static function execute(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array<string, string> job → the trigger of its newest run
     */
    private function runsByJob(App $app): array
    {
        $jobs = [];
        foreach (array_reverse($this->runs($app)->recent(100)) as $run) {
            $jobs[$run->job] = $run->trigger->value;
        }

        return $jobs;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function runReminders(App $app, ?int $userId): JobRun
    {
        return $this->runJob($app, RemindersJob::class, $userId);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param class-string<Job> $class
     */
    private function runJob(App $app, string $class, ?int $userId = null): JobRun
    {
        $job = $this->service($app, JobRegistry::class)->get($this->service($app, $class)->name());
        self::assertNotNull($job);

        return $this->service($app, JobRunner::class)->run($job, JobTrigger::Manual, $userId);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function runs(App $app): JobRunRepository
    {
        return $this->service($app, JobRunRepository::class);
    }

    /**
     * @return resource
     */
    private function holdLock(string $relative)
    {
        $file = Kernel::rootDir() . '/var/cache/' . $relative;
        @mkdir(dirname($file), 0775, true);
        $lock = fopen($file, 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX));

        return $lock;
    }

    /**
     * @param resource $lock
     */
    private function release($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/logbook-jobs-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }

    /**
     * @return list<string>
     */
    private function scheduledFiles(string $dir): array
    {
        $files = array_map('basename', glob($dir . '/logbook-scheduled-*.zip') ?: []);
        sort($files);

        return $files;
    }
}
