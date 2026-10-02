<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DI\Container;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Kernel;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Jobs\JobRegistry;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Scheduler\ScheduledTasks;
use Logbook\Service\Updates\UpdateCheckJob;
use Logbook\Service\Updates\UpdateSettings;
use Logbook\Support\Version\InstalledVersion;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The update check end to end (spec.md §7.31): off until switched on,
 * Settings → Updates, Check now, the dashboard banner for admins, its
 * dismissal per version, and `UPDATE_CHECK_ALLOWED=false`.
 */
final class UpdatesTest extends ReminderTestCase
{
    private const string NOW = '2026-10-02T09:00:00Z';
    private const string UPGRADE = 'https://github.com/gwpreston16/Logbook/blob/v2.12.0/docs/deployment.md#upgrading';

    /** @var list<MockResponse> */
    private array $queue = [];
    /** @var list<string> */
    private array $requested = [];

    public function testNothingIsEverSentUntilSwitchedOn(): void
    {
        $app = $this->app();
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);

        // A scheduler pass, Run now on the Jobs page, and the command line.
        $this->service($app, ScheduledTasks::class)->run(JobTrigger::Cron);
        self::assertNull($this->runs($app)->latest(UpdateCheckJob::NAME), 'never due while off');
        $jobs = self::body($admin->get('/settings/jobs'));
        self::assertStringContainsString('Update check', $jobs);

        $html = self::body($admin->follow($admin->post('/settings/jobs/update_check/run')));
        self::assertStringContainsString('Checking for updates is off; nothing was sent.', $html);

        [$code, $output] = self::execute([PHP_BINARY, Kernel::rootDir() . '/bin/run-job.php', 'update_check']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Checking for updates is off', $output);

        $page = self::body($admin->get('/settings/updates'));
        $unticked = '/<input type="checkbox" name="check" value="1" aria-describedby/';
        self::assertMatchesRegularExpression($unticked, $page, 'unticked');
        self::assertStringNotContainsString('Check now', $page, 'only while checking is on');
        self::assertStringContainsString('<code>api.github.com</code>', $page);
        self::assertStringContainsString('gwpreston16/Logbook', $page, 'names the repository asked');
        self::assertStringNotContainsString('data-admin-notice="update.', self::body($admin->get('/')));
        self::assertSame([], $this->requested, 'GitHub was never contacted');
    }

    public function testCheckNowFindsANewerReleaseAndAdminsSeeTheBanner(): void
    {
        $app = $this->app();
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->createMember($app);
        $this->switchOn($admin);

        $this->queue[] = self::release('v2.12.0');
        $response = $admin->post('/settings/jobs/update_check/run');
        self::assertSame(303, $response->getStatusCode());
        $run = self::body($admin->follow($response));
        self::assertStringContainsString('2.12.0 available (installed 2.11.0)', $run);
        self::assertSame(['https://api.github.com/repos/gwpreston16/Logbook/releases/latest'], $this->requested);

        $page = self::body($admin->get('/settings/updates'));
        self::assertStringContainsString('data-latest-version>2.12.0<', $page);
        self::assertStringContainsString('data-installed-version>2.11.0<', $page);
        self::assertStringContainsString('2.12.0 available (installed 2.11.0)', $page);
        self::assertStringContainsString('Check now', $page);

        $home = self::body($admin->get('/'));
        self::assertStringContainsString('data-admin-notice="update.2.12.0"', $home);
        self::assertStringContainsString('Logbook 2.12.0 is available (you have 2.11.0).', $home);
        self::assertStringContainsString('href="https://github.com/gwpreston16/Logbook/releases/tag/v2.12.0"', $home);
        self::assertStringContainsString('href="' . self::UPGRADE . '"', $home);
        self::assertStringContainsString('Back up, then follow the upgrade steps.', $home, 'bare PHP');
        self::assertStringNotContainsString('docker compose pull', $home);

        $member = $this->browserFor($app, 'partner');
        self::assertStringNotContainsString('data-admin-notice', self::body($member->get('/')), 'admins only');
        self::assertSame(404, $member->get('/settings/updates')->getStatusCode());
        self::assertSame(404, $member->post('/settings/updates', ['check' => '1'])->getStatusCode());
        self::assertSame(404, $member->post('/notices/update.2.12.0/dismiss')->getStatusCode());
    }

    public function testTheSameOrAnOlderReleaseShowsNoBanner(): void
    {
        foreach (
            [
                ['2.12.0', 'Up to date (2.12.0)'],
                ['2.13.0-dev', 'Newer than the latest release (2.13.0-dev)'],
            ] as [$installed, $summary]
        ) {
            $app = $this->app($installed);
            $this->pinClock($app, self::NOW);
            $admin = $this->signedIn($app);
            $this->switchOn($admin);
            $this->queue[] = self::release('v2.12.0');

            $run = $admin->follow($admin->post('/settings/jobs/update_check/run'));
            self::assertStringContainsString($summary, self::body($run));
            self::assertStringNotContainsString('data-admin-notice="update.', self::body($admin->get('/')), $installed);
        }
    }

    public function testADevBuildIsOlderThanItsRelease(): void
    {
        $app = $this->app('2.12.0-dev');
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->switchOn($admin);
        $this->queue[] = self::release('v2.12.0');
        $admin->post('/settings/jobs/update_check/run');

        self::assertStringContainsString('Logbook 2.12.0 is available (you have 2.12.0-dev).', self::body($admin->get('/')));
    }

    public function testWithTheBannerOffTheResultStillShowsOnThePage(): void
    {
        $app = $this->app();
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->switchOn($admin, banner: false);
        $this->queue[] = self::release('v2.12.0');
        $admin->post('/settings/jobs/update_check/run');

        self::assertStringNotContainsString('data-admin-notice="update.', self::body($admin->get('/')));
        self::assertStringContainsString('2.12.0 available (installed 2.11.0)', self::body($admin->get('/settings/updates')));
    }

    public function testDismissHidesThatVersionForThatAdminUntilTheNextRelease(): void
    {
        $app = $this->app();
        $clock = $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->createMember($app, 'second', isAdmin: true, displayName: 'Second Admin');
        $other = $this->browserFor($app, 'second');
        $this->switchOn($admin);
        $this->queue[] = self::release('v2.12.0');
        $admin->post('/settings/jobs/update_check/run');

        $admin->get('/');
        $response = $admin->post('/notices/update.2.12.0/dismiss');
        self::assertSame(303, $response->getStatusCode());
        self::assertStringNotContainsString('data-admin-notice="update.', self::body($admin->get('/')));
        self::assertStringContainsString('data-admin-notice="update.2.12.0"', self::body($other->get('/')), 'per admin');

        $clock->set(new DateTimeImmutable('2026-10-09T09:00:00Z'));
        self::assertStringNotContainsString('data-admin-notice="update.', self::body($admin->get('/')), 'for good, not 24 hours');

        $this->queue[] = self::release('v2.13.0');
        $admin->post('/settings/jobs/update_check/run');
        self::assertStringContainsString('data-admin-notice="update.2.13.0"', self::body($admin->get('/')), 'back for the next');
    }

    public function testDockerInstallsGetTheComposeLine(): void
    {
        $app = $this->app(env: ['LOGBOOK_DOCKER' => '1']);
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->switchOn($admin);
        $this->queue[] = self::release('v2.12.0');
        $admin->post('/settings/jobs/update_check/run');

        $home = self::body($admin->get('/'));
        self::assertStringContainsString('Docker: <code>docker compose pull &amp;&amp; docker compose up -d</code>', $home);
        self::assertStringNotContainsString('Back up, then follow the upgrade steps.', $home);
    }

    public function testAReleaseNameWithHtmlIsEscaped(): void
    {
        $app = $this->app();
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->switchOn($admin);
        $this->queue[] = self::release('v2.12.0', '<img src=x onerror=alert(1)>');
        $admin->post('/settings/jobs/update_check/run');

        foreach (['/', '/settings/updates'] as $path) {
            $html = self::body($admin->get($path));
            self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html, $path);
            self::assertStringNotContainsString('<img src=x', $html, $path);
        }
    }

    public function testGithubErrorsAreOkRunsWithNoBannerAndNoFailureAlert(): void
    {
        $app = $this->app();
        $clock = $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->switchOn($admin);
        $this->queue[] = self::release('v2.12.0');
        $admin->post('/settings/jobs/update_check/run');

        foreach (['2026-10-03T09:00:00Z', '2026-10-04T09:00:00Z'] as $day) {
            $clock->set(new DateTimeImmutable($day));
            $this->queue[] = new MockResponse('', ['http_code' => 503]);
            $admin->post('/settings/jobs/update_check/run');
            self::assertSame(JobStatus::Ok, $this->runs($app)->latest(UpdateCheckJob::NAME)?->status);
        }

        $home = self::body($admin->get('/'));
        self::assertStringNotContainsString('data-admin-notice="update.', $home, 'no banner');
        self::assertStringNotContainsString('data-admin-notice="job_failed.', $home, 'no failure notice');
        self::assertSame([], $this->mail->sent, 'no job_failed notification');
        $page = self::body($admin->get('/settings/updates'));
        self::assertStringContainsString('GitHub answered with status 503.', $page);
        self::assertStringContainsString('data-latest-version>2.12.0<', $page, 'the last good release is kept');
    }

    public function testUpdateCheckAllowedFalseRemovesItEntirely(): void
    {
        $app = $this->app(env: ['UPDATE_CHECK_ALLOWED' => 'false']);
        $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        // Even a stored "on" (say, from a backup) does nothing.
        $this->service($app, UpdateSettings::class)->save(true, true);

        self::assertNull($this->service($app, JobRegistry::class)->get(UpdateCheckJob::NAME));
        self::assertSame(404, $admin->get('/settings/updates')->getStatusCode());
        self::assertSame(404, $admin->post('/settings/jobs/update_check/run')->getStatusCode());
        self::assertStringNotContainsString('/settings/updates', self::body($admin->get('/settings')));
        self::assertStringNotContainsString('Update check', self::body($admin->get('/settings/jobs')));
        $this->service($app, ScheduledTasks::class)->run(JobTrigger::Cron);

        [$code, $output] = self::execute(
            [PHP_BINARY, Kernel::rootDir() . '/bin/run-job.php', 'update_check'],
            ['UPDATE_CHECK_ALLOWED' => 'false'],
        );
        self::assertSame(3, $code, $output);

        $this->resetDatabase($app);
        self::assertStringNotContainsString('update_check', self::body((new TestBrowser($app))->get('/setup')));
        self::assertSame([], $this->requested);
    }

    public function testSetupOffersTheCheckUnticked(): void
    {
        $app = $this->app();
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $browser = new TestBrowser($app);
        $html = self::body($browser->get('/setup'));
        self::assertStringContainsString('Tell me when a new version is out', $html);
        self::assertMatchesRegularExpression('/<input type="checkbox" name="update_check" value="1" aria-describedby/', $html);

        $this->completeSetup($browser, ['update_check' => '1']);
        self::assertTrue($this->service($app, UpdateSettings::class)->checking());

        $this->resetDatabase($app);
        $this->completeSetup(new TestBrowser($app), []);
        self::assertFalse($this->service($app, UpdateSettings::class)->checking(), 'unticked: off');
    }

    public function testTheDailyMinuteIsChosenOnceAndARateLimitDelaysTheNextCheck(): void
    {
        $app = $this->app();
        $clock = $this->pinClock($app, self::NOW);
        $admin = $this->signedIn($app);
        $this->switchOn($admin);
        $settings = $this->service($app, UpdateSettings::class);
        $minute = $settings->minute();
        self::assertSame($minute, $settings->minute());
        self::assertSame($minute, $this->service($app, UpdateSettings::class)->minute(), 'stored, not chosen again');

        // Pin it, so the test knows when the day's check is due: 14:30 UTC.
        $this->service($app, SettingRepository::class)->save(UpdateSettings::MINUTE, 870);
        $job = $this->service($app, JobRegistry::class)->get(UpdateCheckJob::NAME);
        self::assertNotNull($job);
        $runner = $this->service($app, JobRunner::class);
        self::assertFalse($runner->isDue($job, new DateTimeImmutable('2026-10-02T14:29:00Z')));
        self::assertEquals(new DateTimeImmutable('2026-10-02T14:30:00Z'), $runner->nextRun($job, $clock->now()));
        self::assertTrue($runner->isDue($job, new DateTimeImmutable('2026-10-02T14:30:00Z')));

        $clock->set(new DateTimeImmutable('2026-10-02T14:31:00Z'));
        $this->queue[] = new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 172800']]);
        $this->service($app, ScheduledTasks::class)->run(JobTrigger::Cron);
        self::assertCount(1, $this->requested);
        self::assertFalse($runner->isDue($job, new DateTimeImmutable('2026-10-03T14:30:00Z')), 'waits out the limit');
        self::assertTrue($runner->isDue($job, new DateTimeImmutable('2026-10-03T14:31:00Z')), 'at most a day');

        $clock->set(new DateTimeImmutable('2026-10-02T20:00:00Z'));
        $html = self::body($admin->follow($admin->post('/settings/jobs/update_check/run')));
        self::assertStringContainsString('Rate limited by GitHub until', $html);
        self::assertCount(1, $this->requested, 'Check now waits too (#117)');
    }

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    private function app(string $installed = '2.11.0', array $env = []): App
    {
        $this->queue = [];
        $this->requested = [];
        $app = $this->createRecordingApp($env + self::CHANNELS);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(InstalledVersion::class, new InstalledVersion($installed));
        $container->set(HttpClientInterface::class, new MockHttpClient(
            function (string $method, string $url): ResponseInterface {
                $this->requested[] = $url;

                return array_shift($this->queue) ?? self::fail('An unexpected request: ' . $url);
            },
        ));

        return $app;
    }

    private function switchOn(TestBrowser $admin, bool $banner = true): void
    {
        $admin->get('/settings/updates');
        $response = $admin->post('/settings/updates', ['check' => '1'] + ($banner ? ['banner' => '1'] : []));
        self::assertSame(303, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $extra
     */
    private function completeSetup(TestBrowser $browser, array $extra): void
    {
        $browser->get('/setup');
        $response = $browser->post('/setup', [
            'username' => 'owner',
            'display_name' => 'Pat',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
            'units' => 'metric',
            'currency' => 'GBP',
            'locale' => 'en_GB',
            'timezone' => 'Europe/London',
        ] + $extra);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
    }

    private static function release(string $tag, ?string $name = null): MockResponse
    {
        return new MockResponse(json_encode([
            'tag_name' => $tag,
            'html_url' => 'https://github.com/gwpreston16/Logbook/releases/tag/' . $tag,
            'name' => $name ?? 'Logbook ' . ltrim($tag, 'v'),
            'published_at' => '2026-10-01T08:00:00Z',
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function runs(App $app): JobRunRepository
    {
        return $this->service($app, JobRunRepository::class);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private static function execute(array $command, array $env = []): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }
}
