<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Demo;

use DI\Container;
use Logbook\Service\Demo\DemoBootstrap;
use Logbook\Service\Demo\DemoGuardedHttpClient;
use Logbook\Service\Demo\DemoGuardedTransport;
use Logbook\Service\Demo\DemoMode;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Symfony\Component\Mailer\Transport\TransportInterface as MailTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fixtures for demo mode (spec.md §7.36, Phase 35.1): an app with `DEMO_MODE`
 * on and every notification channel configured, whose outbound mail and
 * HTTP are recorded behind the demo's guard, and a seeded demo to sign in to.
 */
abstract class DemoTestCase extends ReminderTestCase
{
    protected const string DEMO_PASSWORD = 'try-the-demo-2026';

    /** The environment of a demo whose channels would all send if they could. */
    protected const array DEMO_ENV = [
        'DEMO_MODE' => 'true',
        'DEMO_PASSWORD' => self::DEMO_PASSWORD,
        'SESSION_SECRET' => 'a-test-session-secret-of-32-chars!',
    ];

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    protected function demoApp(array $env = []): App
    {
        $app = $this->createRecordingApp($env + self::DEMO_ENV + self::CHANNELS);
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $mode = $this->service($app, DemoMode::class);
        // The real wiring's guards, in front of the recorders.
        $container->set(MailTransport::class, new DemoGuardedTransport($this->mail, $mode));
        $container->set(HttpClientInterface::class, new DemoGuardedHttpClient($this->http->client, $mode));

        return $app;
    }

    /**
     * An empty database seeded as a demo, as the first start does.
     *
     * @param App<ContainerInterface> $app
     */
    protected function seedDemo(App $app): void
    {
        $this->resetDatabase($app);
        $this->service($app, DemoMode::class)->forget();
        self::assertTrue($this->service($app, DemoBootstrap::class)->ensure());
        $this->service($app, DemoMode::class)->forget();
    }

    /**
     * A browser signed in as the demo owner, on a freshly seeded demo.
     *
     * @param App<ContainerInterface> $app
     */
    protected function demoBrowser(App $app): TestBrowser
    {
        $this->seedDemo($app);
        $browser = new TestBrowser($app);
        $browser->get('/login');
        $response = $browser->post('/login', ['username' => 'demo', 'password' => self::DEMO_PASSWORD]);
        self::assertSame(303, $response->getStatusCode(), 'signing in to the demo failed');

        return $browser;
    }

    protected static function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
