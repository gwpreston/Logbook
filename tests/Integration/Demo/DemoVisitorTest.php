<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Demo;

use DateTimeImmutable;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Demo\DemoGuardedHttpClient;
use Logbook\Service\Demo\DemoGuardedTransport;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoRoutes;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Jobs\OutputRedactor;
use Logbook\Service\Notification\ReminderNotifier;
use Logbook\Service\Updates\ReleaseChecker;
use Logbook\Service\Updates\UpdateStatus;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\TransportException as MailTransportException;
use Symfony\Component\Mailer\Transport\TransportInterface as MailTransport;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What a visitor to the demo can and cannot do (spec.md §7.36): blocked
 * routes answer a friendly page and are not in the navigation, nothing is
 * sent out, no file goes in, and every page says it is a demo.
 */
final class DemoVisitorTest extends DemoTestCase
{
    public function testEveryBlockedRouteAnswersTheFriendlyPageNeverABareError(): void
    {
        $app = $this->demoApp();
        $browser = $this->demoBrowser($app);

        $checked = 0;
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (!DemoRoutes::isBlocked($name) || DemoRoutes::isApi($name) || $name === 'mcp') {
                continue;
            }
            $path = self::pathFor($route->getPattern());
            foreach ($route->getMethods() as $method) {
                $response = $method === 'GET' ? $browser->get($path) : $browser->post($path);
                $label = $method . ' ' . $path . ' (' . $name . ')';
                self::assertSame(403, $response->getStatusCode(), $label);
                $body = self::body($response);
                self::assertStringContainsString('Not available in the demo', $body, $label);
                self::assertStringContainsString('<a class="btn btn--primary"', $body, $label . ' offers a way back');
                self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'), $label);
                $checked++;
            }
        }

        self::assertGreaterThan(80, $checked);
    }

    public function testTheApiAndMcpAreRefusedWithAJsonProblem(): void
    {
        $app = $this->demoApp();
        $browser = $this->demoBrowser($app);

        foreach (['/api/v1/me', '/api/v1/vehicles', '/api/v1/vehicles/1/fuel'] as $path) {
            $response = $browser->get($path);
            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertStringContainsString('json', $response->getHeaderLine('Content-Type'), $path);
            self::assertStringContainsString('not available in the demo', self::body($response), $path);
        }
        self::assertSame(403, $browser->post('/mcp', [], [], false)->getStatusCode());
        // The API's own description needs no key, and stays.
        self::assertSame(200, $browser->get('/api/v1/openapi.json')->getStatusCode());
    }

    public function testBlockedPagesAreNotInTheNavigation(): void
    {
        $app = $this->demoApp();
        $browser = $this->demoBrowser($app);
        $vehicle = $this->firstVehicleId($app);

        $blockedPaths = [
            '/settings/users', '/settings/backup', '/settings/jobs', '/settings/ai', '/settings/api-keys',
            '/settings/fuel-prices', '/settings/updates', '/settings/import-app', '/settings/password',
            '/settings/email', '/settings/avatar', '/ask', '/scan', '/import/', '/settings/delivery',
            // Phase 36.2: saving, testing, switching and removing one's channels (the page itself stays).
            '/settings/notifications/',
        ];
        $pages = [
            '/', '/garage', '/settings', '/profile', '/insights', '/reminders', '/log/new',
            '/vehicles/' . $vehicle, '/vehicles/' . $vehicle . '/expenses', '/settings/reminders', '/stations',
            '/settings/notifications',
        ];
        foreach ($pages as $page) {
            $response = $browser->get($page);
            self::assertSame(200, $response->getStatusCode(), $page);
            $body = self::body($response);
            foreach ($blockedPaths as $blocked) {
                self::assertStringNotContainsString('href="' . $blocked, $body, $page . ' links to ' . $blocked);
                self::assertStringNotContainsString('action="' . $blocked, $body, $page . ' posts to ' . $blocked);
            }
        }

        // What stays: the modules, and the rest of the settings.
        $settings = self::body($browser->get('/settings'));
        self::assertStringContainsString('href="/settings/modules"', $settings);
        self::assertStringContainsString('href="/profile"', $settings);
        self::assertStringContainsString('href="/settings/reminders"', $settings);
        self::assertStringNotContainsString('settings-group" id="developers"', $settings);
        // No account to change on the profile page, but the preferences stay.
        $profile = self::body($browser->get('/profile'));
        self::assertStringNotContainsString('id="avatar"', $profile);
        self::assertStringNotContainsString('id="email"', $profile);
        self::assertStringContainsString('id="preferences"', $profile);
        // No test message and no calendar feed.
        $reminders = self::body($browser->get('/settings/reminders'));
        self::assertStringNotContainsString('settings/reminders/test', $reminders);
        self::assertStringNotContainsString('settings/reminders/calendar', $reminders);
    }

    public function testAllowedPagesAndEntriesWork(): void
    {
        $app = $this->demoApp();
        $browser = $this->demoBrowser($app);
        $vehicle = $this->firstVehicleId($app);

        foreach (
            [
            '/', '/garage', '/vehicles/' . $vehicle, '/vehicles/' . $vehicle . '/fuel', '/reminders', '/reminders/calendar',
            '/reports', '/upcoming', '/settings', '/settings/modules', '/profile', '/vehicles/' . $vehicle . '/edit',
            '/vehicles/' . $vehicle . '/expenses/new', '/vehicles/' . $vehicle . '/documents/new',
            ] as $path
        ) {
            self::assertSame(200, $browser->get($path)->getStatusCode(), $path);
        }

        // A visitor can add an expense, and it is there.
        $created = $browser->post('/vehicles/' . $vehicle . '/expenses/new', [
            'spent_on' => '2026-09-14', 'category' => 'parking', 'amount' => '3.5', 'note' => 'Demo visitor was here',
        ]);
        self::assertSame(303, $created->getStatusCode());
        $list = self::body($browser->get('/vehicles/' . $vehicle . '/expenses'));
        self::assertStringContainsString('Demo visitor was here', $list);
    }

    public function testNoFileFieldIsOfferedAndAFileInARequestIsRefused(): void
    {
        $app = $this->demoApp();
        $browser = $this->demoBrowser($app);
        $vehicle = $this->firstVehicleId($app);

        foreach (
            [
            '/vehicles/' . $vehicle . '/edit', '/vehicles/' . $vehicle . '/documents/new',
            '/vehicles/' . $vehicle . '/expenses/new', '/vehicles/' . $vehicle . '/maintenance/new', '/vehicles/new',
            ] as $path
        ) {
            $body = self::body($browser->get($path));
            self::assertStringNotContainsString('type="file"', $body, $path . ' offers a file field');
            self::assertStringNotContainsString('enctype="multipart/form-data" action="/settings/', $body, $path);
        }

        $file = tempnam(sys_get_temp_dir(), 'receipt');
        self::assertIsString($file);
        file_put_contents($file, 'not really a png');
        try {
            $upload = new UploadedFile($file, 'receipt.png', 'image/png', (int) filesize($file), UPLOAD_ERR_OK);
            $response = $browser->post('/vehicles/' . $vehicle . '/expenses/new', [
                'spent_on' => '2026-09-14', 'category' => 'parking', 'amount' => '3.5', 'note' => 'With a file',
            ], ['attachments' => [$upload]]);
        } finally {
            @unlink($file);
        }

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Not available in the demo', self::body($response));
        self::assertStringNotContainsString('With a file', self::body($browser->get('/vehicles/' . $vehicle . '/expenses')));
    }

    public function testTheBannerSaysWhenItResetsInTheViewersTimeZone(): void
    {
        $app = $this->demoApp();
        $clock = $this->pinClock($app, '2026-10-06T08:00:00Z');
        $browser = $this->demoBrowser($app);

        // Seeded at 08:00 UTC, so it resets at 08:00 UTC tomorrow (09:00 in the viewer's London summer time).
        $clock->set(new DateTimeImmutable('2026-10-06T09:00:00Z'));
        $body = self::body($browser->get('/'));
        self::assertStringContainsString('data-demo-banner', $body);
        self::assertStringContainsString('This is a demo. It resets at ', $body);
        self::assertStringContainsString('7 Oct 2026', $body);
        self::assertStringContainsString('09:00', $body);
        self::assertStringContainsString('nothing here is private.', $body);

        $clock->set(new DateTimeImmutable('2026-10-07T05:00:00Z'));
        self::assertStringContainsString('It resets in 3 hours', self::body($browser->get('/')));
        $clock->set(new DateTimeImmutable('2026-10-07T07:50:00Z'));
        self::assertStringContainsString('It resets in 10 minutes', self::body($browser->get('/garage')));
    }

    public function testAnAnonymousVisitorIsNotToldTheDemoWasReset(): void
    {
        $app = $this->demoApp();
        $this->seedDemo($app);

        $browser = new TestBrowser($app);
        $browser->get('/login');
        $response = $browser->get('/garage');

        self::assertSame(303, $response->getStatusCode());
        self::assertStringNotContainsString('demo=reset', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('data-demo-reset-notice', self::body($browser->follow($response)));
    }

    public function testTheSignInPageShowsTheCredentialsAsText(): void
    {
        $app = $this->demoApp();
        $this->seedDemo($app);

        $response = $this->get($app, '/login');
        $body = self::body($response);

        self::assertStringContainsString('data-demo-try', $body);
        self::assertStringContainsString(
            'Try it: username <code>demo</code>, password <code>' . self::DEMO_PASSWORD . '</code>',
            $body,
        );
        self::assertStringContainsString('data-demo-fill', $body);
        // No other way in is offered, and there is no mail to reset a password with.
        self::assertStringNotContainsString('/password/forgot', $body);
        self::assertStringNotContainsString('data-sso-start', $body);
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        // Not shown to a visitor of an ordinary install.
        $plain = $this->createApp();
        self::assertStringNotContainsString('data-demo-try', self::body($this->get($plain, '/login')));
        self::assertSame('', $this->get($plain, '/login')->getHeaderLine('X-Robots-Tag'));
    }

    public function testEveryResponseTellsSearchEnginesToStayAway(): void
    {
        $app = $this->demoApp();
        $browser = $this->demoBrowser($app);

        $paths = ['/', '/garage', '/health', '/nowhere-at-all', '/manifest.webmanifest', '/settings/users', '/api/v1/me'];
        foreach ($paths as $path) {
            self::assertSame('noindex, nofollow', $browser->get($path)->getHeaderLine('X-Robots-Tag'), $path);
        }
    }

    public function testNothingLeavesTheDemo(): void
    {
        $app = $this->demoApp();
        $browser = $this->demoBrowser($app);
        $owner = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertNotNull($owner);

        // The sample has reminders due, and every channel is configured: an ordinary instance would send.
        $control = $this->createRecordingApp(
            ['DEMO_MODE' => 'false', 'SESSION_SECRET' => self::DEMO_ENV['SESSION_SECRET']] + self::CHANNELS,
        );
        $sentByAnOrdinaryInstance = $this->service($control, ReminderNotifier::class)->reminders($owner);

        $this->mail->sent = [];
        $this->http->requests = [];
        // A full reminder run through the real job and scheduler pass.
        $this->service($app, JobRunner::class)->pass(JobTrigger::Cron);
        $sent = $this->service($app, ReminderNotifier::class)->reminders($owner);
        $digest = $this->service($app, ReminderNotifier::class)->digest($owner);
        // A test notification, and the transports asked directly.
        $test = $browser->post('/settings/reminders/test');
        // An update check.
        $this->service($app, ReleaseChecker::class)->check(new UpdateStatus());

        self::assertSame(0, $sent);
        self::assertFalse($digest);
        self::assertSame(403, $test->getStatusCode());
        self::assertSame([], $this->mail->sent, 'no mail');
        self::assertSame([], $this->http->requests, 'no outbound request');

        try {
            $email = (new Email())->from('a@example.test')->to('b@example.test')->subject('x')->text('x');
            $this->service($app, MailTransport::class)->send($email);
            self::fail('The mail transport must refuse.');
        } catch (MailTransportException) {
            self::assertSame([], $this->mail->sent);
        }
        try {
            $this->service($app, HttpClientInterface::class)->request('GET', 'https://example.test/');
            self::fail('The HTTP client must refuse.');
        } catch (TransportExceptionInterface) {
            self::assertSame([], $this->http->requests);
        }

        // The control shows the recorders would have seen it, were it not a demo.
        self::assertGreaterThan(0, $sentByAnOrdinaryInstance);
    }

    public function testTheDemoWorksBehindAReverseProxyAtASubpath(): void
    {
        $app = $this->demoApp(['APP_BASE_PATH' => '/logbook']);
        $this->seedDemo($app);

        $browser = new TestBrowser($app);
        $login = self::body($browser->get('/logbook/login'));
        self::assertStringContainsString('action="/logbook/login"', $login);
        self::assertStringContainsString('data-demo-try', $login);
        $signedIn = $browser->post('/logbook/login', ['username' => 'demo', 'password' => self::DEMO_PASSWORD]);
        self::assertSame(303, $signedIn->getStatusCode());
        self::assertSame('/logbook/', $signedIn->getHeaderLine('Location'));

        $home = $browser->get('/logbook/');
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('data-demo-banner', self::body($home));
        self::assertSame('noindex, nofollow', $home->getHeaderLine('X-Robots-Tag'));

        // The blocked page, deep in the app, and the way back from it.
        $blocked = $browser->get('/logbook/settings/users');
        self::assertSame(403, $blocked->getStatusCode());
        self::assertStringContainsString('href="/logbook/"', self::body($blocked));
        self::assertStringNotContainsString('href="/settings', self::body($blocked));
        // A hard refresh on a deep link also works with the prefix stripped.
        self::assertSame(403, $browser->get('/settings/users')->getStatusCode());
        self::assertSame(404, $browser->get('/logbook/setup')->getStatusCode());
    }

    public function testOutsideADemoTheGuardsPassEverythingThrough(): void
    {
        $app = $this->createRecordingApp(self::CHANNELS);
        $mode = $this->service($app, DemoMode::class);
        self::assertFalse($mode->isActive());

        $client = new DemoGuardedHttpClient(new MockHttpClient(new MockResponse('fine')), $mode);
        self::assertSame('fine', $client->request('GET', 'https://example.test/')->getContent());
        self::assertInstanceOf(DemoGuardedHttpClient::class, $client->withOptions(['timeout' => 5]));
        self::assertSame([], iterator_to_array($client->stream([])));

        $transport = new DemoGuardedTransport($this->mail, $mode);
        $email = (new Email())->from('a@example.test')->to('b@example.test')->subject('x')->text('x');
        $transport->send($email);
        self::assertCount(1, $this->mail->sent);
        self::assertSame('recording://', (string) $transport);
    }

    public function testTheRealWiringGuardsTheTransports(): void
    {
        // Whatever the tests swap in, the container's own mail transport and HTTP client are guarded.
        $app = $this->createApp(self::DEMO_ENV + self::CHANNELS);
        self::assertInstanceOf(DemoGuardedTransport::class, $this->service($app, MailTransport::class));
        self::assertInstanceOf(DemoGuardedHttpClient::class, $this->service($app, HttpClientInterface::class));
    }

    public function testTheDemoPasswordIsNeverInAJobsOutput(): void
    {
        $app = $this->demoApp();
        $this->seedDemo($app);

        $this->service($app, JobRunner::class)->pass(JobTrigger::Cron);

        $redactor = $this->service($app, OutputRedactor::class);
        $redacted = $redactor->redact('the password is ' . self::DEMO_PASSWORD . '.');
        self::assertStringNotContainsString(self::DEMO_PASSWORD, $redacted);
        $runs = $this->service($app, JobRunRepository::class)->recent(50);
        self::assertNotEmpty($runs);
        foreach ($runs as $run) {
            self::assertStringNotContainsString(self::DEMO_PASSWORD, (string) $run->summary);
            self::assertStringNotContainsString(self::DEMO_PASSWORD, (string) $run->output);
        }
    }

    public function testTheDemoOwnerIsAnAdminWhoSeesModulesButNothingInstallWide(): void
    {
        $app = $this->demoApp();
        $browser = $this->demoBrowser($app);

        $modules = $browser->get('/settings/modules');
        self::assertSame(200, $modules->getStatusCode());
        self::assertStringContainsString('Modules', self::body($modules));
        self::assertSame(403, $browser->get('/settings/backup')->getStatusCode());
        self::assertSame(403, $browser->get('/settings/jobs')->getStatusCode());
        self::assertSame(403, $browser->get('/settings/users')->getStatusCode());
        self::assertTrue($this->service($app, DemoMode::class)->isActive());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function firstVehicleId(App $app): int
    {
        $user = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertNotNull($user);
        $vehicles = $this->ownedVehicles($app, $user->id, includeArchived: false);
        self::assertNotEmpty($vehicles);

        return $vehicles[0]->id;
    }

    /** A request path for a route pattern, with a value that fits each parameter. */
    private static function pathFor(string $pattern): string
    {
        return (string) preg_replace_callback(
            '/\{[a-z_]+(?::((?:[^{}]|\{\d+\})+))?\}/',
            static function (array $match): string {
                $regex = $match[1] ?? '';

                return match (true) {
                    $regex === '[0-9]+' => '1',
                    $regex === '[A-Za-z0-9_-]{43}' => str_repeat('a', 43),
                    in_array($regex, ['[a-f0-9]{32}', '[0-9a-f]{32}'], true) => str_repeat('a', 32),
                    $regex === '[a-z_]+' => 'reminders',
                    str_starts_with($regex, 'logbook-scheduled-') => 'logbook-scheduled-20261006-090000.zip',
                    !str_contains($regex, '[') => explode('|', $regex)[0],
                    default => 'x',
                };
            },
            $pattern,
        );
    }
}
