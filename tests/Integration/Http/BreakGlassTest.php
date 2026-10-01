<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Kernel;
use Logbook\Repository\UserRepository;
use Logbook\Service\Auth\LoginLinks;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\SingleSignOn;
use Logbook\Tests\Support\TestBrowser;

/**
 * Password sign-in switched off, and the break-glass link (spec.md §7.9):
 * with AUTH_LOCAL_LOGIN=false the form is gone and a password POST is
 * refused; `bin/auth.php login-link` still gets the owner in, once, within
 * ten minutes, with the provider down.
 */
final class BreakGlassTest extends AppTestCase
{
    use SingleSignOn;

    protected function tearDown(): void
    {
        $this->removeOidcCaches();
        parent::tearDown();
    }

    public function testWithLocalSignInOffOnlyTheProviderIsOffered(): void
    {
        [$app] = $this->ssoApp(['AUTH_LOCAL_LOGIN' => 'false']);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        $browser = new TestBrowser($app);
        $page = self::body($browser->get('/login'));
        self::assertStringContainsString('Sign in with Authentik', $page);
        self::assertStringNotContainsString('name="password"', $page, 'the password form is gone');

        // A token from a page that has one (the break-glass page), then a correct password.
        $link = $this->service($app, LoginLinks::class)->create($owner);
        self::assertNotNull($link);
        $browser->get((string) parse_url($link->url, PHP_URL_PATH));
        $refused = $browser->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        self::assertSame(403, $refused->getStatusCode());
        self::assertStringContainsString('Signing in with a password is switched off here.', self::body($refused));
        self::assertSame(303, $browser->get('/')->getStatusCode(), 'not signed in');
    }

    public function testWithLocalSignInOffAndNoSsoThePageSaysHowToGetIn(): void
    {
        $app = $this->createApp(['AUTH_LOCAL_LOGIN' => 'false']);
        $this->resetDatabase($app);
        $this->createOwner($app);

        $page = self::body($this->get($app, '/login'));

        self::assertStringContainsString('php bin/auth.php login-link', $page);
        self::assertStringNotContainsString('name="password"', $page);
    }

    public function testABreakGlassLinkSignsInOnceWithTheProviderDown(): void
    {
        [$app, $idp] = $this->ssoApp(['AUTH_LOCAL_LOGIN' => 'false']);
        $idp->discoveryOverrides = ['issuer' => 'https://down.example/'];
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);

        $link = $this->service($app, LoginLinks::class)->create($owner);
        self::assertNotNull($link);
        self::assertMatchesRegularExpression('~^http://localhost:8080/login/link/[A-Za-z0-9_-]{43}$~', $link->url);
        $path = (string) parse_url($link->url, PHP_URL_PATH);

        $browser = new TestBrowser($app);
        $page = $browser->get($path);
        self::assertSame(200, $page->getStatusCode(), 'opening it uses nothing up (link previews)');
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        self::assertSame(200, (new TestBrowser($app))->get($path)->getStatusCode());
        $before = $browser->sessionCookie();
        $signedIn = $browser->post($path);
        self::assertSame(303, $signedIn->getStatusCode());
        self::assertNotSame($before, $browser->sessionCookie(), 'a new session id');
        self::assertStringContainsString('Signed in with a one-time link, Pat Owner.', self::body($browser->follow($signedIn)));

        $other = new TestBrowser($app);
        self::assertSame(404, $other->get($path)->getStatusCode(), 'once');
        $asInvite = '/invite/' . substr($path, strlen('/login/link/'));
        self::assertSame(404, $this->get($app, $asInvite)->getStatusCode(), 'not an invitation');
    }

    public function testABreakGlassLinkExpiresAfterTenMinutesAndANewOneReplacesIt(): void
    {
        $app = $this->createApp(['AUTH_LOCAL_LOGIN' => 'false']);
        $clock = $this->pinClock($app, '2026-10-01T10:00:00Z');
        $this->resetDatabase($app);
        $owner = $this->createOwner($app);
        $links = $this->service($app, LoginLinks::class);

        $first = $links->create($owner);
        $second = $links->create($owner);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(404, $this->get($app, (string) parse_url($first->url, PHP_URL_PATH))->getStatusCode(), 'replaced');

        $clock->set($clock->now()->modify('+9 minutes'));
        self::assertSame(200, $this->get($app, (string) parse_url($second->url, PHP_URL_PATH))->getStatusCode());
        $clock->set($clock->now()->modify('+61 seconds'));
        self::assertSame(404, $this->get($app, (string) parse_url($second->url, PHP_URL_PATH))->getStatusCode(), 'expired');
    }

    public function testADisabledUserGetsNoLink(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app);
        $member = $this->createMember($app);
        $links = $this->service($app, LoginLinks::class);
        $open = $links->create($member);
        self::assertNotNull($open);

        $users = $this->service($app, UserRepository::class);
        $users->setDisabledAt($member->id, new \DateTimeImmutable(), new \DateTimeImmutable());

        self::assertNull($links->create($users->find($member->id) ?? $member));
        $openPath = (string) parse_url($open->url, PHP_URL_PATH);
        self::assertSame(404, $this->get($app, $openPath)->getStatusCode(), 'an open one stops working');
    }

    public function testTheCommandPrintsTheLinkAlone(): void
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->createOwner($app, 'pat');

        [$code, $stdout, $stderr] = self::command('login-link', 'Pat');
        self::assertSame(0, $code, $stderr);
        self::assertMatchesRegularExpression('~^http://localhost:8080/login/link/[A-Za-z0-9_-]{43}\n$~', $stdout);
        self::assertStringContainsString('valid for 10 minutes', $stderr);
        self::assertSame(200, $this->get($app, (string) parse_url(trim($stdout), PHP_URL_PATH))->getStatusCode());

        self::assertSame(1, self::command('login-link', 'nobody')[0]);
        self::assertSame(2, self::command()[0]);
        self::assertSame(2, self::command('reset', 'pat')[0]);
    }

    public function testSetupStillCreatesALocalAdminWithAPassword(): void
    {
        [$app] = $this->ssoApp(['AUTH_LOCAL_LOGIN' => 'false', 'OIDC_AUTO_CREATE' => 'true']);
        $this->resetDatabase($app);

        $browser = new TestBrowser($app);
        $browser->get('/setup');
        $done = $browser->post('/setup', [
            'username' => 'first', 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD,
            'display_name' => 'First', 'locale' => 'en_GB', 'timezone' => 'Europe/London', 'currency' => 'GBP', 'units' => 'uk',
        ]);

        self::assertSame(303, $done->getStatusCode());
        $user = $this->service($app, UserRepository::class)->findByUsername('first');
        self::assertTrue($user?->hasPassword());
        self::assertTrue($user->isAdmin);
    }

    /**
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function command(string ...$args): array
    {
        $process = proc_open(
            [PHP_BINARY, Kernel::rootDir() . '/bin/auth.php', ...array_values($args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
