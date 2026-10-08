<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Kernel;
use Logbook\Repository\JobRunRepository;
use Logbook\Service\Jobs\BackupSchedule;
use Logbook\Service\Jobs\JobSettings;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Settings → Jobs, the run page, the dashboard's admin notices and the
 * two fallback triggers (spec.md §7.30).
 */
final class JobsPagesTest extends ReminderTestCase
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

    public function testMembersGet404OnEveryJobsRoute(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $run = $this->runs($app)->start('reminders', JobTrigger::Cron, null, new DateTimeImmutable(self::NOW));
        $this->createMember($app);
        $member = $this->browserFor($app, 'partner');

        $pages = [
            '/settings/jobs',
            "/settings/jobs/runs/$run",
            "/settings/jobs/runs/$run/status",
            '/settings/jobs/reminders/started',
        ];
        foreach ($pages as $path) {
            self::assertSame(404, $member->get($path)->getStatusCode(), $path);
        }
        $forms = [
            '/settings/jobs/reminders/run',
            '/settings/jobs/triggers',
            '/settings/jobs/url-token',
            '/settings/jobs/backup',
            '/notices/scheduler/dismiss',
        ];
        foreach ($forms as $path) {
            self::assertSame(404, $member->post($path)->getStatusCode(), $path);
        }
        self::assertStringNotContainsString('/settings/jobs', self::body($member->get('/settings')));
        self::assertStringNotContainsString('data-admin-notice', self::body($member->get('/')), 'no notices for members');
        self::assertCount(1, $this->runs($app)->recent(10), 'a member ran nothing');
    }

    public function testThePageWarnsAtTwiceTheIntervalWithTheCronLineForThisInstall(): void
    {
        $app = $this->createRecordingApp();
        $clock = $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);

        $html = self::body($admin->get('/settings/jobs'));
        self::assertStringContainsString('Reminders aren’t being sent automatically: the scheduler has never run.', $html);
        $cron = '*/15 * * * * cd ' . Kernel::rootDir() . ' &amp;&amp; php bin/run-scheduled-tasks.php';
        self::assertStringContainsString($cron, $html);
        self::assertStringContainsString('/settings/jobs', self::body($admin->get('/settings')), 'linked from Settings');

        $this->pass($app, JobTrigger::Cron);
        $clock->set(new DateTimeImmutable('2026-09-27T10:29:00Z'));
        $html = self::body($admin->get('/settings/jobs'));
        self::assertStringNotContainsString('data-scheduler-stale', $html);
        self::assertStringContainsString('The scheduler last ran 27 Sept 2026, 11:00 (cron).', $html);

        $clock->set(new DateTimeImmutable('2026-09-27T10:31:00Z'));
        $html = self::body($admin->get('/settings/jobs'));
        self::assertStringContainsString('the scheduler last ran 27 Sept 2026, 11:00.', $html);
        $health = $this->json(self::body($this->get($app, '/health')));
        self::assertSame(['last_pass' => '2026-09-27T10:00:00Z', 'stale' => true], $health['scheduler']);
        self::assertSame(200, $this->get($app, '/health')->getStatusCode(), 'never changes the status code');
    }

    public function testRunNowWithoutJsRunsTheJobAndRedirectsToItsRun(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->document($app, $this->vehicle($app), '2026-10-09');
        $admin->get('/settings/jobs');

        $response = $admin->post('/settings/jobs/reminders/run');
        self::assertSame(303, $response->getStatusCode());
        self::assertMatchesRegularExpression('#^/settings/jobs/runs/(\d+)$#', $response->getHeaderLine('Location'));
        $html = self::body($admin->follow($response));
        self::assertStringContainsString('Reminders run', $html);
        self::assertStringContainsString('Checked 1 account; sent 1 reminder', $html);
        self::assertStringContainsString('run by hand', $html);
        self::assertStringContainsString('Pat Owner', $html);
        self::assertStringContainsString('Finished reminders: ok.', $html);
        self::assertStringNotContainsString('data-run-poll', $html, 'finished: nothing to poll');
        self::assertCount(1, $this->mail->sent);

        $run = $this->runs($app)->latest('reminders');
        self::assertNotNull($run);
        self::assertSame(JobTrigger::Manual, $run->trigger);
        self::assertSame($this->owner($app)->id, $run->userId);
        $status = $this->json(self::body($admin->get("/settings/jobs/runs/{$run->id}/status")));
        self::assertSame('ok', $status['status']);
        self::assertTrue($status['finished']);
        $started = $this->json(self::body($admin->get('/settings/jobs/reminders/started?after=0')));
        self::assertSame("/settings/jobs/runs/{$run->id}", $started['url']);
        $later = $this->json(self::body($admin->get("/settings/jobs/reminders/started?after={$run->id}")));
        self::assertNull($later['url']);

        // A pass in the meantime is never taken for this admin's run.
        $this->pass($app, JobTrigger::Cron);
        $later = $this->json(self::body($admin->get("/settings/jobs/reminders/started?after={$run->id}")));
        self::assertNull($later['url']);
        self::assertSame(404, $admin->post('/settings/jobs/nope/run')->getStatusCode());
    }

    public function testARunningRunPollsAndALockedOneLinksToTheHolder(): void
    {
        $app = $this->createRecordingApp();
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $holder = $this->runs($app)->start('reminders', JobTrigger::Cron, null, new DateTimeImmutable(self::NOW));

        $html = self::body($admin->get("/settings/jobs/runs/$holder"));
        self::assertStringContainsString('data-run-poll="/settings/jobs/runs/' . $holder . '/status"', $html);
        $status = $this->json(self::body($admin->get("/settings/jobs/runs/$holder/status")));
        self::assertFalse($status['finished']);

        $lock = $this->holdLock('locks/job-reminders.lock');
        try {
            $response = $admin->post('/settings/jobs/reminders/run');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $html = self::body($admin->follow($response));
        self::assertStringContainsString('Already running (started 27 Sept 2026, 11:00 by cron).', $html);
        self::assertStringContainsString('href="/settings/jobs/runs/' . $holder . '"', $html);
        self::assertSame(JobStatus::SkippedLocked, $this->runs($app)->latest('reminders')?->status);
    }

    public function testTheDashboardNoticeDismissesFor24HoursAndComesBack(): void
    {
        $app = $this->createRecordingApp();
        $clock = $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->vehicle($app);

        $html = self::body($admin->get('/'));
        self::assertStringContainsString('data-admin-notice="scheduler"', $html);
        self::assertStringContainsString('the scheduler has never run.', $html);

        $response = $admin->post('/notices/scheduler/dismiss');
        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('data-admin-notice', self::body($admin->get('/')));

        $clock->set(new DateTimeImmutable('2026-09-28T09:59:00Z'));
        self::assertStringNotContainsString('data-admin-notice', self::body($admin->get('/')));
        $clock->set(new DateTimeImmutable('2026-09-28T10:00:01Z'));
        self::assertStringContainsString('data-admin-notice="scheduler"', self::body($admin->get('/')), 'back while it lasts');

        $this->pass($app, JobTrigger::Cron);
        self::assertStringNotContainsString('data-admin-notice', self::body($admin->get('/')), 'gone once a pass has run');
    }

    public function testPageVisitsRunADuePassOnceAndNothingWhenOff(): void
    {
        $app = $this->createRecordingApp();
        $clock = $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);

        self::assertStringNotContainsString('data-scheduler-beacon', self::body($admin->get('/settings')));
        self::assertSame(404, $this->tick($admin), 'off');

        $admin->get('/settings/jobs');
        $admin->post('/settings/jobs/triggers', ['page_visit' => '1']);
        self::assertTrue($this->service($app, JobSettings::class)->pageVisits());
        self::assertStringContainsString('data-scheduler-beacon="/_scheduler/tick"', self::body($admin->get('/settings')));

        // Another pass holds the lock: the beacon waits for nobody and runs nothing.
        $lock = $this->holdLock('scheduled-tasks.lock');
        try {
            self::assertSame(204, $this->tick($admin));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame([], $this->runs($app)->recent(10));

        // Two beacons: one pass.
        self::assertSame([204, 204], [$this->tick($admin), $this->tick($admin)]);
        $runs = $this->runs($app)->recent(10);
        self::assertSame(['cleanup', 'webhooks', 'digest', 'reminders'], array_map(static fn ($r): string => $r->job, $runs));
        $triggers = array_values(array_unique(array_map(static fn ($r): string => $r->trigger->value, $runs)));
        self::assertSame(['page_visit'], $triggers);
        self::assertStringNotContainsString('data-scheduler-beacon', self::body($admin->get('/settings')), 'not due now');

        $clock->set(new DateTimeImmutable('2026-09-27T10:15:00Z'));
        self::assertStringContainsString('data-scheduler-beacon', self::body($admin->get('/settings')));
        $this->tick($admin);
        self::assertCount(7, $this->runs($app)->recent(10), 'reminders, digest and webhooks again; cleanup is hourly');

        $admin->post('/settings/jobs/triggers', []);
        self::assertSame(404, $this->tick($admin), 'off again');
    }

    public function testTheExternalUrlRunsADuePassWithItsToken(): void
    {
        $app = $this->createRecordingApp(['SESSION_SECRET' => 'a-test-session-secret-of-32-chars!'] + self::CHANNELS);
        $clock = $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $nobody = str_repeat('ab', 32);
        self::assertSame(404, $this->get($app, "/cron/$nobody")->getStatusCode(), 'off');

        $admin->get('/settings/jobs');
        $html = self::body($admin->post('/settings/jobs/triggers', ['url' => '1']));
        self::assertSame(1, preg_match('#https://garage\.example/cron/([a-f0-9]{64})#', $html, $m), 'shown once, in full');
        $token = $m[1] ?? '';
        self::assertStringNotContainsString($token, self::body($admin->get('/settings/jobs')), 'never shown again');

        $response = $this->get($app, "/cron/$token");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('account(s) checked', self::body($response));
        self::assertSame('url', $this->runs($app)->latest('reminders')?->trigger->value);
        self::assertSame(404, $this->get($app, "/cron/$nobody")->getStatusCode(), 'a wrong token');

        $clock->set(new DateTimeImmutable('2026-09-27T10:00:30Z'));
        $response = $this->get($app, "/cron/$token");
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->getHeaderLine('Retry-After'));

        $clock->set(new DateTimeImmutable('2026-09-27T10:01:01Z'));
        self::assertSame(200, $this->get($app, "/cron/$token")->getStatusCode());
        $cleanups = array_filter($this->runs($app)->recent(20), static fn ($r): bool => $r->job === 'cleanup');
        self::assertCount(1, $cleanups, 'only due jobs: cleanup is hourly');

        $html = self::body($admin->post('/settings/jobs/url-token'));
        self::assertSame(1, preg_match('#/cron/([a-f0-9]{64})#', $html, $m));
        $fresh = $m[1] ?? '';
        self::assertNotSame($token, $fresh);
        $clock->set(new DateTimeImmutable('2026-09-27T10:05:00Z'));
        self::assertSame(404, $this->get($app, "/cron/$token")->getStatusCode(), 'the old token stops working');
        self::assertSame(200, $this->get($app, '/cron/' . $fresh)->getStatusCode());
    }

    public function testTheBackupScheduleFormAndTheScheduledFiles(): void
    {
        $dir = sys_get_temp_dir() . '/logbook-jobs-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;
        $app = $this->createRecordingApp(['BACKUP_PATH' => $dir] + self::CHANNELS);
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $admin->get('/settings/jobs');

        $response = $admin->post('/settings/jobs/backup', ['schedule' => 'daily', 'keep' => '0']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter a whole number from 1 to 60.', self::body($response));
        $response = $admin->post('/settings/jobs/backup', ['schedule' => 'daily', 'keep' => '3']);
        self::assertSame('/settings/jobs', $response->getHeaderLine('Location'));
        self::assertSame(BackupSchedule::Daily, $this->service($app, JobSettings::class)->backupSchedule());
        self::assertSame(3, $this->service($app, JobSettings::class)->backupKeep());
        self::assertStringContainsString('Daily', self::body($admin->get('/settings/jobs')));

        file_put_contents($dir . '/logbook-scheduled-20260926-031500.zip', 'PK-test');
        file_put_contents($dir . '/logbook-backup-2026-09-26-031500.zip', 'PK-own');
        $html = self::body($admin->get('/settings/backup'));
        self::assertStringContainsString('logbook-scheduled-20260926-031500.zip', $html);
        self::assertStringNotContainsString('logbook-backup-2026-09-26-031500.zip', $html, 'only scheduled files are listed');

        $download = $admin->get('/settings/backup/files/logbook-scheduled-20260926-031500.zip');
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('application/zip', $download->getHeaderLine('Content-Type'));
        self::assertSame('PK-test', self::body($download));
        self::assertSame(404, $admin->get('/settings/backup/files/logbook-backup-2026-09-26-031500.zip')->getStatusCode());
        $missing = $admin->get('/settings/backup/files/logbook-scheduled-20260101-000000.zip');
        self::assertSame(404, $missing->getStatusCode(), 'no such file');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(string $body): array
    {
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function tick(TestBrowser $browser): int
    {
        return $browser->post('/_scheduler/tick')->getStatusCode();
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function pass(App $app, JobTrigger $trigger): void
    {
        $this->service($app, ScheduledTasks::class)->run($trigger);
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
}
