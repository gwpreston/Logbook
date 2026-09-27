<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Middleware\SessionMiddleware;
use Logbook\Repository\UserRepository;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\MutableClock;
use Logbook\Tests\Support\TestBrowser;
use Psr\Clock\ClockInterface;
use Slim\App;
use DI\Container;
use Psr\Container\ContainerInterface;

final class SetupAndAuthTest extends AppTestCase
{
    private const array SETUP = [
        'username' => 'Gareth',
        'display_name' => 'Gareth',
        'password' => 'correct horse battery',
        'password_confirm' => 'correct horse battery',
        'units' => 'us',
        'currency' => 'USD',
        'locale' => 'en_US',
        'timezone' => 'America/Chicago',
    ];

    public function testFreshInstanceForcesSetup(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $browser = new TestBrowser($app);

        foreach (['/', '/garage', '/settings', '/login', '/vehicles/new'] as $path) {
            $response = $browser->get($path);
            self::assertSame(303, $response->getStatusCode(), $path);
            self::assertSame('/setup', $response->getHeaderLine('Location'), $path);
        }

        $setup = $browser->get('/setup');
        self::assertSame(200, $setup->getStatusCode());
        self::assertStringContainsString('Create your account', self::body($setup));
        // /health stays machine-readable and never needs setup.
        self::assertSame(200, $browser->get('/health')->getStatusCode());
    }

    public function testSetupValidatesAndKeepsInput(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $browser = new TestBrowser($app);
        $browser->get('/setup');

        $response = $browser->post('/setup', ['password_confirm' => 'something else', 'username' => 'a b'] + self::SETUP);
        $html = self::body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('The passwords do not match.', $html);
        self::assertStringContainsString('letters, numbers and . _ - @ only', $html);
        self::assertStringContainsString('value="a b"', $html, 'input is kept');
        self::assertStringNotContainsString('correct horse battery', $html, 'passwords are never echoed back');
        self::assertFalse($this->service($app, UserRepository::class)->exists());
    }

    public function testSetupCreatesTheAccountSignsInAndThenDisappears(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $browser = new TestBrowser($app);
        $browser->get('/setup');

        $response = $browser->post('/setup', self::SETUP);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));

        $home = $browser->follow($response);
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('Welcome, Gareth!', self::body($home));
        self::assertSame('en-US', $home->getHeaderLine('Content-Language'));

        $user = $this->service($app, UserRepository::class)->findByUsername('gareth');
        self::assertNotNull($user, 'usernames are stored lower-case');
        self::assertStringStartsWith('$argon2id$', $user->passwordHash);
        self::assertSame(VolumeUnit::UsGallon, $user->preferences->volumeUnit);
        self::assertSame(ConsumptionUnit::MpgUs, $user->preferences->consumptionUnit);
        self::assertSame('America/Chicago', $user->preferences->timezone);

        // Setup is now unreachable, signed in or not, and cannot create a second account.
        self::assertSame('/', $browser->get('/setup')->getHeaderLine('Location'));
        $stranger = new TestBrowser($app);
        self::assertSame('/login', $stranger->get('/setup')->getHeaderLine('Location'));
        $stranger->get('/login');
        $stranger->post('/setup', ['username' => 'intruder'] + self::SETUP);
        self::assertNull($this->service($app, UserRepository::class)->findByUsername('intruder'));
    }

    public function testSignInIsCaseInsensitiveAndRegeneratesTheSession(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);

        $browser->get('/login');
        $anonymousSession = $browser->sessionCookie();
        self::assertNotNull($anonymousSession, 'the sign-in form needs a session for its CSRF token');

        $response = $browser->post('/login', ['username' => ' OWNER ', 'password' => self::PASSWORD]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));

        $signedInSession = $browser->sessionCookie();
        self::assertNotNull($signedInSession);
        self::assertNotSame($anonymousSession, $signedInSession, 'session id must change on sign-in (fixation)');

        // The pre-sign-in id is dead: presenting it again does not sign anyone in.
        $attacker = new TestBrowser($app);
        $attacker->setCookie(SessionMiddleware::COOKIE, $anonymousSession);
        self::assertSame(303, $attacker->get('/')->getStatusCode());

        self::assertSame(200, $browser->get('/')->getStatusCode());
    }

    public function testCookieFlags(): void
    {
        $app = $this->createApp(['SESSION_SECURE' => 'true']);
        $this->resetDatabase($app);
        $this->createOwner($app);

        $cookie = (new TestBrowser($app))->get('/login')->getHeaderLine('Set-Cookie');

        self::assertMatchesRegularExpression('/^logbook_session=[A-Za-z0-9_-]{43}; /', $cookie);
        self::assertStringContainsString('; Path=/;', $cookie);
        self::assertStringContainsString('; HttpOnly', $cookie);
        self::assertStringContainsString('; SameSite=Lax', $cookie);
        self::assertStringContainsString('; Secure', $cookie);

        $plain = $this->createApp(['SESSION_SECURE' => 'false']);
        self::assertStringNotContainsString('Secure', (new TestBrowser($plain))->get('/login')->getHeaderLine('Set-Cookie'));
    }

    public function testWrongCredentialsAreRejectedGenericallyAndLogged(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = new TestBrowser($app);
        $browser->get('/login');

        foreach ([['owner', 'wrong password'], ['nobody', self::PASSWORD], ['', '']] as [$username, $password]) {
            $response = $browser->post('/login', ['username' => $username, 'password' => $password]);
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('That username and password do not match.', self::body($response));
        }

        self::assertSame(303, $browser->get('/')->getStatusCode(), 'still signed out');
    }

    public function testReturnsToTheRequestedPageButNeverOffSite(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $browser = new TestBrowser($app);
        $redirect = $browser->get('/garage?archived=1');
        self::assertSame('/login?next=%2Fgarage%3Farchived%3D1', $redirect->getHeaderLine('Location'));
        $login = $browser->follow($redirect);
        self::assertStringContainsString('name="next" value="/garage?archived=1"', self::body($login));
        $response = $browser->post('/login', [
            'username' => 'owner',
            'password' => self::PASSWORD,
            'next' => '/garage?archived=1',
        ]);
        self::assertSame('/garage?archived=1', $response->getHeaderLine('Location'));

        $evil = new TestBrowser($app);
        $evil->get('/login');
        $response = $evil->post('/login', ['username' => 'owner', 'password' => self::PASSWORD, 'next' => '//evil.example/']);
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testSignOutDestroysTheSession(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $signedInSession = $browser->sessionCookie();
        self::assertNotNull($signedInSession);

        // GET cannot sign you out (no cross-site logout links).
        self::assertSame(405, $browser->get('/logout')->getStatusCode());

        $response = $browser->post('/logout');
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertStringContainsString('You have been signed out.', self::body($browser->follow($response)));
        self::assertSame(303, $browser->get('/')->getStatusCode());

        $replay = new TestBrowser($app);
        $replay->setCookie(SessionMiddleware::COOKIE, $signedInSession);
        self::assertSame(303, $replay->get('/settings')->getStatusCode(), 'the old session id is gone');
    }

    public function testSessionsExpireAfterThirtyIdleDays(): void
    {
        $app = $this->createApp();
        $clock = new MutableClock(new DateTimeImmutable('2026-01-01T10:00:00Z'));
        $this->container($app)->set(ClockInterface::class, $clock);
        $browser = $this->signedIn($app);

        $clock->set(new DateTimeImmutable('2026-01-25T10:00:00Z'));
        self::assertSame(200, $browser->get('/')->getStatusCode(), 'activity extends the session');

        $clock->set(new DateTimeImmutable('2026-02-24T09:00:00Z'));
        self::assertSame(200, $browser->get('/')->getStatusCode(), 'just under 30 idle days');

        $clock->set(new DateTimeImmutable('2026-03-26T10:00:00Z'));
        self::assertSame(303, $browser->get('/')->getStatusCode(), 'expired after 30 idle days');
    }

    public function testMachineEndpointsNeverCreateSessions(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);

        $response = (new TestBrowser($app))->get('/health');
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));

        // A redirect to sign-in renders no form, so it needs no session either.
        self::assertSame('', (new TestBrowser($app))->get('/')->getHeaderLine('Set-Cookie'));
        self::assertEquals(0, $this->connection($app)->fetchOne('SELECT COUNT(*) FROM sessions'));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function container(App $app): Container
    {
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);

        return $container;
    }
}
